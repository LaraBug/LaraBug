<?php

namespace LaraBug\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;

class BoostResourcesTest extends TestCase
{
    #[Test]
    public function it_ships_a_single_guideline()
    {
        $guidelines = glob(self::boostPath('guidelines/*'));

        $this->assertSame(
            [self::boostPath('guidelines/core.blade.php')],
            $guidelines,
            'Boost keeps one guideline per third-party package, so extra files silently overwrite each other.'
        );
    }

    #[Test]
    #[DataProvider('skillDirectories')]
    public function it_ships_a_skill_boost_can_discover(string $directory, string $path)
    {
        $skillFile = $path . '/SKILL.md';

        $this->assertFileExists($skillFile);

        $frontmatter = $this->parseFrontmatter((string) file_get_contents($skillFile));

        $this->assertNotEmpty(
            $frontmatter['name'] ?? null,
            'Boost drops a skill whose frontmatter has no name, without reporting it.'
        );

        $this->assertNotEmpty(
            $frontmatter['description'] ?? null,
            'Boost drops a skill whose frontmatter has no description, without reporting it.'
        );

        $this->assertSame(
            $directory,
            $frontmatter['name'],
            'Boost installs a skill under its frontmatter name rather than its directory name.'
        );
    }

    /** @return array<int, array{0: string, 1: string}> */
    public static function skillDirectories(): array
    {
        return array_map(
            fn (string $path): array => [basename($path), $path],
            glob(self::boostPath('skills/*'), GLOB_ONLYDIR)
        );
    }

    /** @return array<string, mixed> */
    private function parseFrontmatter(string $content): array
    {
        if (! preg_match('/^\s*---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            $this->fail('The skill has no YAML frontmatter block for Boost to read.');
        }

        $frontmatter = Yaml::parse($matches[1]);

        $this->assertIsArray($frontmatter, 'The skill frontmatter is not a YAML mapping.');

        return $frontmatter;
    }

    private static function boostPath(string $path): string
    {
        return __DIR__ . '/../resources/boost/' . $path;
    }
}
