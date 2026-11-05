<?php

namespace Modules\WordPressChat\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Every user-facing string of the module has a German translation. */
class TranslationsTest extends TestCase
{
    const JSON_BLIND = '<Laravel 5.5 reads no JSON here; use __() (plural: trans_choice(__(\'…|…\'), $n)):';

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /** All source files outside Tests/ and vendor/. */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->moduleRoot(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen($this->moduleRoot()) + 1);
            if (preg_match('#^(Tests|vendor|node_modules|\.git)/#', $relative) || substr($path, -4) !== '.php') {
                continue;
            }
            $files[$relative] = $path;
        }

        return $files;
    }

    public function test_scanner_skips_method_calls()
    {
        $keys = $this->keysIn('<?php $x?->trans("a"); $y->trans("b"); __("c");');

        $this->assertSame(['c'], array_keys($keys));
    }

    /** Literal of one PHP string token, unescaped. */
    private function literal(string $token): string
    {
        $quote = $token[0];
        $body = substr($token, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
        }

        return stripcslashes($body);
    }

    /**
     * Keys found as the first argument of __(), trans(), trans_choice() and
     * @lang(), keyed by key => "file".
     */
    private function keys(): array
    {
        $keys = [];
        foreach ($this->sourceFiles() as $relative => $path) {
            $code = file_get_contents($path);

            if (substr($relative, -10) === '.blade.php') {
                // Blade is mostly HTML: scan the literals with a regex.
                // __() and @lang() read JSON; trans(), trans_choice() and @choice() do not.
                if (preg_match_all('/(?<![\w$])(?<!->)(?<!::)(__|trans_choice|trans|@lang|@choice)\(\s*(?:([\'"])((?:\\\\.|(?!\2).)*)\2)/s', $code, $m)) {
                    foreach ($m[3] as $index => $key) {
                        $key = $m[2][$index] === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $key) : stripcslashes($key);
                        if (in_array($m[1][$index], ['__', '@lang'], true)) {
                            $keys[$key] = $relative;
                        } else {
                            $keys[self::JSON_BLIND.' '.$m[1][$index].'(\''.$key.'\') in '.$relative] = $relative;
                        }
                    }
                }
                continue;
            }

            $keys += $this->keysIn($code, $relative);
        }

        return $keys;
    }

    /**
     * Keys of the __(), trans() and trans_choice() calls in one piece of PHP
     * code, keyed by key => $relative. Method and static calls and
     * declarations are skipped (->trans(, ?->trans(, ::trans(, function trans).
     */
    private function keysIn(string $code, string $relative = 'code'): array
    {
        $keys = [];
        $skip = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION];
        if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
            $skip[] = constant('T_NULLSAFE_OBJECT_OPERATOR');
        }

        $tokens = token_get_all($code);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }
            // PHP 8 reads \__('x') as one T_NAME_FULLY_QUALIFIED token.
            $isName = $tokens[$i][0] === T_STRING
                || (defined('T_NAME_FULLY_QUALIFIED') && $tokens[$i][0] === constant('T_NAME_FULLY_QUALIFIED'));
            $name = ltrim($tokens[$i][1], '\\');
            if (!$isName || !in_array($name, ['__', 'trans', 'trans_choice'], true)) {
                continue;
            }
            // Skip method calls such as ->trans( and ::trans(.
            $prev = $i - 1;
            while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                $prev--;
            }
            if ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], $skip, true)) {
                continue;
            }
            $j = $i + 1;
            while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($tokens[$j] !== '(') {
                continue;
            }
            $j++;
            $key = '';
            $found = false;
            for (; $j < $count; $j++) {
                $t = $tokens[$j];
                if (is_array($t) && $t[0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $key .= $this->literal($t[1]);
                    $found = true;
                    continue;
                }
                if ($t === '.') {
                    continue;
                }
                break;
            }
            if ($found && $name === '__') {
                $keys[$key] = $relative;
            } elseif ($found) {
                // trans() and trans_choice() never read JSON in Laravel 5.5.
                $keys[self::JSON_BLIND.' '.$name.'(\''.$key.'\') in '.$relative.':'.$tokens[$i][2]] = $relative;
            } elseif ($name !== 'trans_choice') {
                // trans_choice($var, $n) and trans_choice(__('…|…'), $n) are
                // trusted: the first argument is translated by __() elsewhere.
                $keys['<non-literal in '.$relative.':'.$tokens[$i][2].'>'] = $relative;
            }
        }

        return $keys;
    }

    public function test_every_module_string_has_a_german_translation()
    {
        $german = json_decode(file_get_contents($this->moduleRoot().'/Resources/lang/de.json'), true);
        $this->assertIsArray($german, 'Resources/lang/de.json must be valid JSON');

        $keys = $this->keys();
        $this->assertGreaterThan(50, count($keys), 'The scanner found suspiciously few strings');

        $problems = [];
        foreach ($keys as $key => $file) {
            if ($key[0] === '<') {
                $problems[] = $key;
            } elseif (!isset($german[$key]) || trim((string) $german[$key]) === '') {
                $problems[] = $key.'  ('.$file.')';
            }
        }

        $this->assertSame([], $problems, "Missing German translations:\n".implode("\n", $problems));
    }

    /**
     * A key that starts lowercase is a fragment for a :placeholder inside
     * a sentence ("Email to the visitor failed: :reason"). German
     * capitalizes nouns, so the fragment's German may start uppercase, but
     * never with an article or pronoun, which only a sentence start would
     * capitalize.
     */
    public function test_german_fragments_do_not_start_like_a_sentence()
    {
        $german = json_decode(file_get_contents($this->moduleRoot().'/Resources/lang/de.json'), true);

        $problems = [];
        foreach ($german as $key => $value) {
            if (preg_match('/^[a-z]/', $key) && preg_match('/^(?:Der|Die|Das|Den|Dem|Des|Ein|Eine|Einen|Einem|Einer|Eines|Kein|Keine|Keinen|Keinem|Keiner|Es|Sie|Er)\b/u', $value)) {
                $problems[] = $key.' => '.$value;
            }
        }

        $this->assertSame([], $problems, "German fragments that start like a sentence:\n".implode("\n", $problems));
    }

    public function test_german_has_no_dead_entries_and_keeps_placeholders()
    {
        $german = json_decode(file_get_contents($this->moduleRoot().'/Resources/lang/de.json'), true);
        $keys = $this->keys();

        foreach ($german as $key => $value) {
            $this->assertArrayHasKey($key, $keys, 'Unused entry in de.json: '.$key);

            preg_match_all('/:[a-z_]+|\{n\}/', $key, $a);
            preg_match_all('/:[a-z_]+|\{n\}/', $value, $b);
            $this->assertEqualsCanonicalizing(array_unique($a[0]), array_unique($b[0]), 'Placeholders differ in: '.$key);
            $this->assertSame(substr_count($key, '|'), substr_count($value, '|'), 'Plural structure differs in: '.$key);
        }
    }
}
