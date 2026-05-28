<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Core\Bandits;
use Avsbhq\Avsb\Core\BanditModelStore;
use PHPUnit\Framework\TestCase;

final class BanditsTest extends TestCase
{
    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function actions(array $ids = ['action_a', 'action_b', 'action_c']): array
    {
        return array_map(
            static fn (string $id): array => ['id' => $id, 'variationId' => "var_{$id}"],
            $ids
        );
    }

    private function perAction(array $means = []): array
    {
        $out = [];
        foreach ($means as $id => $mean) {
            $out[$id] = ['mean' => (float) $mean, 'variance' => 1.0, 'samples' => 10];
        }
        return $out;
    }

    private function inMemoryStore(?array $models = null): BanditModelStore
    {
        return new class ($models ?? []) implements BanditModelStore {
            public function __construct(private array $models) {}
            public function getModel(string $banditKey): ?array
            {
                return $this->models[$banditKey] ?? null;
            }
            public function updateModel(string $banditKey, array $model): void
            {
                $this->models[$banditKey] = $model;
            }
        };
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function testEpsilonGreedyExploitsHighMeanAction(): void
    {
        // With a hash bucket in the exploit range, the action with the
        // highest mean must win.
        $perAction = $this->perAction([
            'action_a' => 0.2,
            'action_b' => 0.8, // highest
            'action_c' => 0.5,
        ]);
        $actions = $this->actions();
        // bucket 5000 → fraction 0.5 > epsilon (0.1), so exploit fires.
        $result = Bandits::epsilonGreedy($perAction, $actions, 5000, 0.1);
        self::assertSame('action_b', $result['action']['id']);
        self::assertFalse($result['exploreFired']);
    }

    public function testEpsilonGreedyExploresFiredAtLowBucket(): void
    {
        $perAction = $this->perAction(['action_a' => 1.0, 'action_b' => 0.0]);
        $actions   = $this->actions(['action_a', 'action_b']);
        // bucket 0 → fraction 0.0 < epsilon 0.1 → explore fires.
        $result = Bandits::epsilonGreedy($perAction, $actions, 0, 0.1);
        self::assertTrue($result['exploreFired']);
    }

    public function testEpsilonGreedyThrowsOnEmptyActions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Bandits::epsilonGreedy([], [], 0);
    }

    public function testThompsonSampleReturnsDeterministicAction(): void
    {
        $perAction = $this->perAction(['action_a' => 0.1, 'action_b' => 0.9]);
        $actions   = $this->actions(['action_a', 'action_b']);
        $r1 = Bandits::thompsonSample($perAction, $actions, 42);
        $r2 = Bandits::thompsonSample($perAction, $actions, 42);
        self::assertSame($r1['action']['id'], $r2['action']['id']);
    }

    public function testThompsonSampleThrowsOnEmptyActions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Bandits::thompsonSample([], [], 0);
    }

    public function testUcb1ColdStartPicksFirstUnseen(): void
    {
        // All actions have samples = 0 → cold-start policy picks first by alpha order.
        $actions = $this->actions(['z_action', 'a_action', 'm_action']);
        $result  = Bandits::ucb1([], $actions);
        self::assertSame('a_action', $result['action']['id']);
    }

    public function testUcb1ThrowsOnEmptyActions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Bandits::ucb1([], []);
    }

    public function testSeededGaussianIsDeterministic(): void
    {
        $z1 = Bandits::seededGaussian(12345);
        $z2 = Bandits::seededGaussian(12345);
        self::assertSame($z1, $z2);
    }

    public function testSeededGaussianDifferentSeedsProduceDifferentValues(): void
    {
        self::assertNotSame(
            Bandits::seededGaussian(1),
            Bandits::seededGaussian(2)
        );
    }

    public function testEvaluateBanditReturnsNullWhenModelMissing(): void
    {
        $config = ['algorithm' => 'epsilon-greedy', 'rewardMetric' => 'ctr', 'actions' => $this->actions()];
        $store  = $this->inMemoryStore();
        $result = Bandits::evaluateBandit('my-bandit', $config, ['kind' => 'user', 'key' => 'alice'], $store);
        self::assertNull($result);
    }

    public function testEvaluateBanditReturnsActionAndLogEntry(): void
    {
        $actions = $this->actions(['action_a', 'action_b']);
        $config  = [
            'algorithm'      => 'epsilon-greedy',
            'explorationRate'=> 0.1,
            'rewardMetric'   => 'ctr',
            'actions'        => $actions,
        ];
        $model = [
            'version'   => 'v1',
            'algorithm' => 'epsilon-greedy',
            'perAction' => $this->perAction(['action_a' => 0.1, 'action_b' => 0.9]),
        ];
        $store  = $this->inMemoryStore(['my-bandit' => $model]);
        $result = Bandits::evaluateBandit('my-bandit', $config, ['kind' => 'user', 'key' => 'alice'], $store);

        self::assertNotNull($result);
        self::assertArrayHasKey('action', $result);
        self::assertArrayHasKey('banditEntry', $result);
        self::assertSame('my-bandit', $result['banditEntry']['banditKey']);
        self::assertSame('bandit', $result['banditEntry']['source']);
    }
}
