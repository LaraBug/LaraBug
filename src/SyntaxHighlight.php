<?php namespace LaraBug;

class SyntaxHighlight
{

    private $tokens = array();

    public static function process($s)
    {
        $class = get_called_class();
        $obj = new $class;

        return $obj->highlight($s);
    } // end process

    public function highlight($s)
    {
        $s = htmlspecialchars($s);

        // Workaround for escaped backslashes
        $s = str_replace('\\\\', '\\\\<e>', $s);

        $regexp = array(
            // Numbers (also look for Hex)
            '/(?<!\w)(
                (0x|\#)[\da-f]+|
                \d+|
                \d+(px|em|cm|mm|rem|s|\%)
            )(?!\w)/ix'
            => '<span class="exception-number">$1</span>', // 8CD0D3

            // Make the bold assumption that an
            // all uppercase word has a special meaning
            '/(?<!\w|>|\#)(
                [A-Z_0-9]{2,}
            )(?!\w)/x'
            => '<span class="exception-uppercase">$1</span>', // FFFFFF

            // Keywords
            '/(?<!\w|\$|\%|\@|>)(
                and|or|xor|for|do|while|foreach|as|return|die|exit|if|then|else|
                elseif|new|delete|try|throw|catch|finally|class|function|string|
                array|object|resource|var|bool|boolean|int|integer|float|double|
                real|string|array|global|const|static|public|private|protected|
                published|extends|switch|true|false|null|void|this|self|struct|
                char|signed|unsigned|short|long
            )(?!\w|=")/ix'
            => '<span class="exception-keyword">$1</span>', // DFC47D

            // PHP/Perl-Style Vars: $var, %var, @var
            '/(?<!\w)(
                (\$|\%|\@)(\-&gt;|\w)+
            )(?!\w)/ix'
            => '<span class="exception-var">$1</span>', // CEDF99
        );

        // Comments/Strings
        $s = preg_replace_callback('/(
                \/\*.*?\*\/|
                \/\/.*?\n|
                \#.[^a-fA-F0-9]+?\n|
                \&lt;\!\-\-[\s\S]+\-\-\&gt;|
                (?<!\\\)&quot;.*?(?<!\\\)&quot;|
                (?<!\\\)\'(.*?)(?<!\\\)\'
            )/isx', [$this, 'replaceId'], $s);

        $s = preg_replace(array_keys($regexp), array_values($regexp), $s);

        // Paste the comments and strings back in again
        $s = str_replace(array_keys($this->tokens), array_values($this->tokens), $s);

        // Delete the "Escaped Backslash Workaround Token" (TM)
        // and replace tabs with four spaces.
        $s = str_replace(array('<e>', "\t"), array('', '    '), $s);

        return $s;
    } // end highlight

    /*
     * Regexp-Callback to replace every comment or string with a uniqid and save
     * the matched text in an array
     * This way, strings and comments will be stripped out and wont be processed
     * by the other expressions searching for keywords etc.
     */
    private function replaceId($matches)
    {
        $match = $matches[0];
        $id = "##r" . uniqid() . "##";

        // String or Comment?
        if (substr($match, 0, 2) == '//' || substr($match, 0, 2) == '/*' || substr($match, 0, 2) == '##' || substr($match, 0, 7) == '&lt;!--') {
            $this->tokens[$id] = '<span class="exception-comment">' . $match . '</span>'; // #7F9F7F
        } else {
            $this->tokens[$id] = '<span class="exception-string">' . $match . '</span>'; // #CC9385
        }
        return $id;
    } // end replaceId
}

