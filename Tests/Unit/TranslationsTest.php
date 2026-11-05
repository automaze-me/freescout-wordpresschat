<?php

namespace Modules\WordPressChat\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Every user-facing string of the module has a German translation. */
class TranslationsTest extends TestCase
{
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
                if (preg_match_all('/(?<![\w>:$])(?:__|trans|trans_choice|@lang)\(\s*(?:([\'"])((?:\\\\.|(?!\1).)*)\1)/s', $code, $m)) {
                    foreach ($m[2] as $index => $key) {
                        $keys[$m[1][$index] === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $key) : stripcslashes($key)] = $relative;
                    }
                }
                continue;
            }

            $tokens = token_get_all($code);
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING
                    || !in_array($tokens[$i][1], ['__', 'trans', 'trans_choice'], true)) {
                    continue;
                }
                // Skip method calls such as ->trans( and ::trans(.
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                    $prev--;
                }
                if ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
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
                if ($found) {
                    $keys[$key] = $relative;
                } elseif ($tokens[$i][1] !== 'trans_choice') {
                    // trans_choice($line, $n) with a variable: the $line came from __().
                    $keys['<non-literal in '.$relative.':'.$tokens[$i][2].'>'] = $relative;
                }
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
