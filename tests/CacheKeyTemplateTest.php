<?php

namespace LaraBug\Tests;

use LaraBug\Requests\CacheKeyTemplate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

class CacheKeyTemplateTest extends TestCase
{
    #[Test]
    #[DataProvider('collapsedTypes')]
    public function it_collapses_every_type_it_claims_to(string $key, string $expected)
    {
        $this->assertSame($expected, CacheKeyTemplate::template($key));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function collapsedTypes(): array
    {
        return [
            'integer' => ['user:8213:profile', 'user:{int}:profile'],
            'integer alone' => ['42', '{int}'],
            'uuid' => ['user:9f8b7c6d-1234-4abc-8def-0123456789ab', 'user:{uuid}'],
            'uuid uppercase' => ['9F8B7C6D-1234-4ABC-8DEF-0123456789AB', '{uuid}'],
            'ulid' => ['order:01ARZ3NDEKTSV4RRFFQ69G5FAV', 'order:{ulid}'],
            'md5' => ['cache:'.self::md5(), 'cache:{hash}'],
            'sha1' => ['cache:'.self::sha1(), 'cache:{hash}'],
            'sha256' => ['cache:'.self::sha256(), 'cache:{hash}'],
            'email' => ['login:john.doe@example.com', 'login:{email}'],
            'email with plus tag' => ['john.doe+news@example.co.uk', '{email}'],
            'date' => ['report:2024-01-15', 'report:{datetime}'],
            'date with slashes' => ['report:2024/01/15', 'report:{datetime}'],
            'datetime with T and Z' => ['report:2024-01-15T10:30:00Z', 'report:{datetime}'],
            'datetime with a space' => ['report:2024-01-15 10:30:00', 'report:{datetime}'],
            'datetime with offset' => ['report:2024-01-15T10:30:00.123456+02:00', 'report:{datetime}'],
            'session id' => [self::sessionId(), '{session}'],
        ];
    }

    #[Test]
    public function two_keys_that_differ_only_by_id_template_to_one_key()
    {
        $this->assertSame(
            CacheKeyTemplate::template('user:8213:profile'),
            CacheKeyTemplate::template('user:44:profile')
        );
    }

    #[Test]
    public function a_key_it_does_not_recognise_passes_through_literally()
    {
        foreach (['config:mail', 'laravel_session', 'v1:users:list', 'deadbeef', 'queue@redis'] as $key) {
            $this->assertSame($key, CacheKeyTemplate::template($key));
        }
    }

    #[Test]
    public function it_collapses_every_placeholder_in_one_mixed_key()
    {
        $key = 'v2:tenant:44:'.self::uuid().':2024-01-15:'.self::md5().':owner@example.com:'.self::sessionId();

        $this->assertSame(
            'v2:tenant:{int}:{uuid}:{datetime}:{hash}:{email}:{session}',
            CacheKeyTemplate::template($key)
        );
    }

    #[Test]
    public function it_templates_across_whatever_delimiter_the_application_chose()
    {
        $this->assertSame('user_{int}_profile', CacheKeyTemplate::template('user_8213_profile'));
        $this->assertSame('user/{int}/profile', CacheKeyTemplate::template('user/8213/profile'));
        $this->assertSame('user|{int}|profile', CacheKeyTemplate::template('user|8213|profile'));
        $this->assertSame('user.{int}.profile', CacheKeyTemplate::template('user.8213.profile'));
    }

    /**
     * The ordering the whole thing turns on: all three of these contain
     * characters that are delimiters everywhere else, so a splitter that ran
     * first would leave three fragments that never collapse.
     */
    #[Test]
    public function it_masks_datetimes_emails_and_uuids_before_it_splits()
    {
        $this->assertSame('{datetime}', CacheKeyTemplate::template('2024-01-15T10:30:00'));
        $this->assertSame('{email}', CacheKeyTemplate::template('john.doe@example.com'));
        $this->assertSame('{uuid}', CacheKeyTemplate::template(self::uuid()));
    }

    #[Test]
    public function it_only_matches_a_placeholder_that_fills_a_whole_segment()
    {
        // A uuid with a tail is not a uuid, and collapsing it whole would merge
        // two keys that are genuinely different. Its digit run is still a digit
        // run, which is the documented behaviour and never widens a group.
        $templated = CacheKeyTemplate::template(self::uuid().'x');

        $this->assertNotSame('{uuid}', $templated);
        $this->assertStringEndsWith('0123456789abx', $templated);

        // A digit run welded to a word is part of the word, not an id.
        $this->assertSame('x8213', CacheKeyTemplate::template('x8213'));
    }

    #[Test]
    public function an_empty_key_stays_empty()
    {
        $this->assertSame('', CacheKeyTemplate::template(''));
    }

    #[Test]
    public function a_key_that_is_not_utf8_becomes_one_group()
    {
        $this->assertSame('{binary}', CacheKeyTemplate::template("cache\x80\xffkey"));

        // A truncated two-byte sequence: valid start, invalid continuation.
        $this->assertSame('{binary}', CacheKeyTemplate::template("\xc3\x28"));
    }

    #[Test]
    public function a_very_long_key_is_bounded_on_the_way_in_and_out()
    {
        $key = str_repeat('segment:8213:', 5000);

        $templated = CacheKeyTemplate::template($key);

        // The panel stores 255; sending more would only be silently cut there.
        $this->assertLessThanOrEqual(255, mb_strlen($templated));
        $this->assertStringStartsWith('segment:{int}:', $templated);
    }

    #[Test]
    public function a_long_key_is_still_valid_utf8_after_it_is_cut()
    {
        $templated = CacheKeyTemplate::template(str_repeat('héllo:', 5000));

        $this->assertTrue(mb_check_encoding($templated, 'UTF-8'));
    }

    /**
     * The property that matters more than any single collapse: a key the
     * templater cannot read is a key that passes through, never one that
     * raises into the application's own stack.
     */
    #[Test]
    public function no_key_can_make_it_throw()
    {
        mt_srand(1);

        for ($i = 0; $i < 2000; $i++) {
            $key = '';

            for ($j = 0, $length = mt_rand(0, 80); $j < $length; $j++) {
                $key .= chr(mt_rand(0, 255));
            }

            $this->assertIsString(CacheKeyTemplate::template($key));
        }
    }

    /**
     * There is no regex in the templater, so there is no pattern to back off
     * into. These are the shapes that would bait one.
     */
    #[Test]
    public function a_hostile_key_stays_linear()
    {
        $baits = [
            str_repeat('9f8b7c6d-1234-4abc-8def-0123456789a', 2000),
            str_repeat('a.', 50000).'@',
            str_repeat('2024-01-', 20000),
            str_repeat(':', 100000),
        ];

        $started = microtime(true);

        foreach ($baits as $bait) {
            CacheKeyTemplate::template($bait);
        }

        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    #[Test]
    public function the_source_holds_no_regex_at_all()
    {
        $source = file_get_contents(__DIR__.'/../src/Requests/CacheKeyTemplate.php');

        foreach (['preg_match', 'preg_replace', 'preg_split', 'preg_quote', 'mb_ereg'] as $function) {
            $this->assertStringNotContainsString($function.'(', $source);
        }
    }

    private static function uuid(): string
    {
        return '9f8b7c6d-1234-4abc-8def-0123456789ab';
    }

    private static function md5(): string
    {
        return str_repeat('a1b2', 8);
    }

    private static function sha1(): string
    {
        return str_repeat('a1b2', 10);
    }

    private static function sha256(): string
    {
        return str_repeat('a1b2', 16);
    }

    /** Str::random(40): forty mixed-case alphanumerics, and no colon in sight. */
    private static function sessionId(): string
    {
        return 'eZ9kQ2mVx7LpR4tYw1NbC8sHj3Ku6AdF5gTzXv0Q';
    }
}
