<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\TestUtils;

use Avsbhq\Avsb\AvsbServer;

/**
 * InMemoryDatafileLoader — test utility for constructing AvsbServer
 * instances from in-memory or file-based datafile fixtures.
 *
 * Use this in tests where you need a fully-configured server without
 * making HTTP calls to the A vs B API.
 */
final class InMemoryDatafileLoader
{
    /**
     * Create an AvsbServer from a decoded PHP array.
     */
    public static function fromArray(array $datafile): AvsbServer
    {
        return new AvsbServer($datafile);
    }

    /**
     * Create an AvsbServer from a JSON string.
     *
     * @throws \JsonException When the string is not valid JSON.
     */
    public static function fromJson(string $json): AvsbServer
    {
        $datafile = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return new AvsbServer($datafile);
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
     * server — useful when you need to inspect or mutate the datafile
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
