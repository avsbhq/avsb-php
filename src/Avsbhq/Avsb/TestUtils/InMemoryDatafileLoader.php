<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\TestUtils;

use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\AvsbServerOptions;

/**
 * InMemoryDatafileLoader: builds an AvsbServer from a datafile fixture.
 *
 * Every server it returns has events and heartbeats switched off, so a test
 * suite never touches the network by accident.
 */
final class InMemoryDatafileLoader
{
    /**
     * Create an AvsbServer from a decoded PHP array.
     *
     * @param array<string, mixed> $datafile
     */
    public static function fromArray(array $datafile): AvsbServer
    {
        return AvsbServer::fromDatafile($datafile, new AvsbServerOptions(
            events: false,
            heartbeat: false,
        ));
    }

    /**
     * Create an AvsbServer from a JSON string.
     *
     * @throws \JsonException When the string is not valid JSON.
     */
    public static function fromJson(string $json): AvsbServer
    {
        /** @var array<string, mixed> $datafile */
        $datafile = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($datafile);
    }

    /**
     * Create an AvsbServer from a JSON file path.
     *
     * @throws \RuntimeException When the file cannot be read.
     * @throws \JsonException    When the file content is not valid JSON.
     */
    public static function fromFile(string $path): AvsbServer
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("InMemoryDatafileLoader: cannot read file: {$path}");
        }
        return self::fromJson($contents);
    }

    /**
     * Load a raw datafile array from a JSON file without constructing a
     * server, useful when you need to inspect or mutate the datafile
     * before passing it to AvsbServer.
     *
     * @throws \RuntimeException When the file cannot be read.
     * @throws \JsonException    When the file content is not valid JSON.
     */
    public static function loadArray(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("InMemoryDatafileLoader: cannot read file: {$path}");
        }
        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
