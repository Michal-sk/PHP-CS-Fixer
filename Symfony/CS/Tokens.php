<?php

/*
 * This file is part of the PHP CS utility.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Symfony\CS;

/**
 * Collection of code tokens.
 * As a token prototype you should understand a single element generated by token_get_all.
 *
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 */
class Tokens extends \SplFixedArray
{
    /**
     * Static class cache.
     * @type array
     */
    private static $cache = array();

    /**
     * crc32 hash of code string.
     * @type array
     */
    private $codeHash;

    /**
     * Clear cache - one position or all of them.
     *
     * @param int|string|null $key position to clear, when null clear all
     */
    public static function clearCache($key = null)
    {
        if (null === $key) {
            static::$cache = array();

            return;
        }

        if (static::hasCache($key)) {
            unset(static::$cache[$key]);
        }
    }

    /**
     * Get cache value for given key.
     *
     * @param  int|string $key item key
     * @return misc       item value
     */
    private static function getCache($key)
    {
        if (!static::hasCache($key)) {
            throw new \OutOfBoundsException('Unknown cache key: '.$key);
        }

        return static::$cache[$key];
    }

    /**
     * Check if given key exists in cache.
     *
     * @param  int|string $key item key
     * @return bool
     */
    private static function hasCache($key)
    {
        return isset(static::$cache[$key]);
    }

    /**
     * Set cache item.
     *
     * @param int|string $key   item key
     * @param int|string $value item value
     */
    private static function setCache($key, $value)
    {
        static::$cache[$key] = $value;
    }

    /**
     * Check if given tokens are equal.
     * If tokens are arrays, then only keys defined in second token are checked.
     *
     * @param  string|array $tokenA token prototype
     * @param  string|array $tokenB token prototype or only few keys of it
     * @return bool
     */
    public static function compare($tokenA, $tokenB)
    {
        $tokenAIsArray = is_array($tokenA);
        $tokenBIsArray = is_array($tokenB);

        if ($tokenAIsArray !== $tokenBIsArray) {
            return false;
        }

        if (!$tokenAIsArray) {
            return $tokenA === $tokenB;
        }

        foreach ($tokenB as $key => $val) {
            if ($tokenA[$key] !== $val) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create token collection from array.
     *
     * @param  array  $array       the array to import
     * @param  bool   $saveIndexes save the numeric indexes used in the original array, default is yes
     * @return Tokens
     */
    public static function fromArray($array, $saveIndexes = null)
    {
        $tokens = new Tokens(count($array));

        if (null === $saveIndexes || $saveIndexes) {
            foreach ($array as $key => $val) {
                $tokens[$key] = $val;
            }

            return $tokens;
        }

        $index = 0;

        foreach ($array as $val) {
            $tokens[$index++] = $val;
        }

        return $tokens;
    }

    /**
     * Create token collection directly from code.
     *
     * @param  string $code PHP code
     * @return Tokens
     */
    public static function fromCode($code)
    {
        $codeHash = crc32($code);

        if (static::hasCache($codeHash)) {
            return static::getCache($codeHash);
        }

        $tokens = token_get_all($code);

        foreach ($tokens as $index => $tokenPrototype) {
            $tokens[$index] = new Token($tokenPrototype);
        }

        $collection = static::fromArray($tokens);
        $collection->changeCodeHash($codeHash);

        return $collection;
    }

    /**
     * Check whether passed method name is one of magic methods.
     *
     * @param string $content name of method
     *
     * @return bool is method a magical
     */
    public static function isMethodNameIsMagic($name)
    {
        static $magicMethods = array(
            '__construct', '__destruct', '__call', '__callStatic', '__get', '__set', '__isset', '__unset',
            '__sleep', '__wakeup', '__toString', '__invoke', '__set_state', '__clone',
        );

        return in_array($name, $magicMethods, true);
    }

    /**
     * Apply token attributes.
     * Token at given index is prepended by attributes.
     *
     * @param int   $index   token index
     * @param array $attribs array of token attributes
     */
    public function applyAttribs($index, $attribs)
    {
        $toInsert = array();

        foreach ($attribs as $attrib) {
            if (null !== $attrib && '' !== $attrib->content) {
                $toInsert[] = $attrib;
                $toInsert[] = new Token(' ');
            }
        }

        if (!empty($toInsert)) {
            $this->insertAt($index, $toInsert);
        }
    }

    /**
     * Change code hash.
     * Remove old cache and set new one.
     *
     * @param string $codeHash new code hash
     */
    private function changeCodeHash($codeHash)
    {
        if (null !== $this->codeHash) {
            static::clearCache($this->codeHash);
        }

        $this->codeHash = $codeHash;
        static::setCache($this->codeHash, $this);
    }

    /**
     * Find tokens of given kind.
     *
     * @param  int|array $possibleKind kind or array of kind
     * @return array     array of tokens of given kinds or assoc array of arrays
     */
    public function findGivenKind($possibleKind)
    {
        $this->rewind();

        $elements = array();
        $possibleKinds = is_array($possibleKind) ? $possibleKind : array($possibleKind, );

        foreach ($possibleKinds as $kind) {
            $elements[$kind] = array();
        }

        foreach ($this as $index => $token) {
            if ($token->isGivenKind($possibleKinds)) {
                $elements[$token->id][$index] = $token;
            }
        }

        return is_array($possibleKind) ? $elements : $elements[$possibleKind];
    }

    /**
     * Generate code from tokens.
     *
     * @return string
     */
    public function generateCode()
    {
        $code = '';
        $this->rewind();

        foreach ($this as $token) {
            $code .= $token->content;
        }

        $this->changeCodeHash(crc32($code));

        return $code;
    }

    /**
     * Get indexes of methods and properties in classy code (classes, interfaces and traits).
     */
    public function getClassyElements()
    {
        $this->rewind();

        $elements = array();
        $inClass = false;
        $curlyBracesLevel = 0;
        $bracesLevel = 0;

        foreach ($this as $index => $token) {
            if ($token->isGivenKind(T_ENCAPSED_AND_WHITESPACE)) {
                continue;
            }

            if (!$inClass) {
                $inClass = $token->isClassy();
                continue;
            }

            if ('(' === $token->content) {
                ++$bracesLevel;
                continue;
            }

            if (')' === $token->content) {
                --$bracesLevel;
                continue;
            }

            if ('{' === $token->content || $token->isGivenKind(array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, ))) {
                ++$curlyBracesLevel;
                continue;
            }

            if ('}' === $token->content) {
                --$curlyBracesLevel;

                if (0 === $curlyBracesLevel) {
                    $inClass = false;
                }

                continue;
            }

            if (1 !== $curlyBracesLevel || !$token->isArray()) {
                continue;
            }

            if (T_VARIABLE === $token->id && 0 === $bracesLevel) {
                $elements[$index] = array('token' => $token, 'type' => 'property', );
                continue;
            }

            if (T_FUNCTION === $token->id) {
                $elements[$index] = array('token' => $token, 'type' => 'method', );
            }
        }

        return $elements;
    }

    /**
     * Get closest next token which is non whitespace.
     * This method is shorthand for getNonWhitespaceSibling method.
     *
     * @param  int      $index       token index
     * @param  array    $opts        array of extra options for isWhitespace method
     * @param  int|null &$foundIndex index of founded token, if any
     * @return Token    token
     */
    public function getNextNonWhitespace($index, array $opts = array(), &$foundIndex = null)
    {
        return $this->getNonWhitespaceSibling($index, 1, $opts, $foundIndex);
    }

    /**
     * Get closest next token of given kind.
     * This method is shorthand for getTokenOfKindSibling method.
     *
     * @param  int      $index       token index
     * @param  array    $tokens      possible tokens
     * @param  int|null &$foundIndex index of founded token, if any
     * @return Token    token
     */
    public function getNextTokenOfKind($index, array $tokens = array(), &$foundIndex = null)
    {
        return $this->getTokenOfKindSibling($index, 1, $tokens, $foundIndex);
    }

    /**
     * Get closest sibling token which is non whitespace.
     *
     * @param  int      $index       token index
     * @param  int      $direction   direction for looking, +1 or -1
     * @param  array    $opts        array of extra options for isWhitespace method
     * @param  int|null &$foundIndex index of founded token, if any
     * @return Token    token
     */
    public function getNonWhitespaceSibling($index, $direction, array $opts = array(), &$foundIndex = null)
    {
        while (true) {
            $index += $direction;

            if (!$this->offsetExists($index)) {
                return null;
            }

            $token = $this[$index];

            if (!$token->isWhitespace($opts)) {
                $foundIndex = $index;

                return $token;
            }
        }
    }

    /**
     * Get closest previous token which is non whitespace.
     * This method is shorthand for getNonWhitespaceSibling method.
     *
     * @param  int      $index       token index
     * @param  array    $opts        array of extra options for isWhitespace method
     * @param  int|null &$foundIndex index of founded token, if any
     * @return Token    token
     */
    public function getPrevNonWhitespace($index, array $opts = array(), &$foundIndex = null)
    {
        return $this->getNonWhitespaceSibling($index, -1, $opts, $foundIndex);
    }

    /**
     * Get closest previous token of given kind.
     * This method is shorthand for getTokenOfKindSibling method.
     *
     * @param  int      $index       token index
     * @param  array    $tokens      possible tokens
     * @param  int|null &$foundIndex index of founded token, if any
     * @return Token    token
     */
    public function getPrevTokenOfKind($index, array $tokens = array(), &$foundIndex = null)
    {
        return $this->getTokenOfKindSibling($index, -1, $tokens, $foundIndex);
    }

    /**
     * Get closest sibling token of given kind.
     *
     * @param  int      $index       token index
     * @param  int      $direction   direction for looking, +1 or -1
     * @param  array    $tokens      possible tokens
     * @param  int|null &$foundIndex index of founded token, if any
     * @return Token    token
     */
    public function getTokenOfKindSibling($index, $direction, array $tokens = array(), &$foundIndex = null)
    {
        while (true) {
            $index += $direction;

            if (!$this->offsetExists($index)) {
                return null;
            }

            $token = $this[$index];

            foreach ($tokens as $tokenKind) {
                if (static::compare($token->getPrototype(), $tokenKind)) {
                    $foundIndex = $index;

                    return $token;
                }
            }
        }
    }

    /**
     * Grab attributes before method token at gixen index.
     * It's a shorthand for grabAttribsBeforeToken method.
     *
     * @param  int   $index token index
     * @return array array of grabbed attributes
     */
    public function grabAttribsBeforeMethodToken($index)
    {
        static $tokenAttribsMap = array(
            T_PRIVATE => 'visibility',
            T_PROTECTED => 'visibility',
            T_PUBLIC => 'visibility',
            T_ABSTRACT => 'abstract',
            T_FINAL => 'final',
            T_STATIC => 'static',
        );

        return $this->grabAttribsBeforeToken(
            $index,
            $tokenAttribsMap,
            array(
                'abstract' => null,
                'final' => null,
                'visibility' => new Token(array(T_PUBLIC, 'public', )),
                'static' => null,
            )
        );
    }

    /**
     * Grab attributes before property token at gixen index.
     * It's a shorthand for grabAttribsBeforeToken method.
     *
     * @param  int   $index token index
     * @return array array of grabbed attributes
     */
    public function grabAttribsBeforePropertyToken($index)
    {
        static $tokenAttribsMap = array(
            T_VAR => null, // destroy T_VAR token!
            T_PRIVATE => 'visibility',
            T_PROTECTED => 'visibility',
            T_PUBLIC => 'visibility',
            T_STATIC => 'static',
        );

        return $this->grabAttribsBeforeToken(
            $index,
            $tokenAttribsMap,
            array(
                'visibility' => new Token(array(T_PUBLIC, 'public', )),
                'static' => null,
            )
        );
    }

    /**
     * Grab attributes before token at gixen index.
     * Grabbed attributes are cleared by overriding them with empty string and should be manually applied with applyTokenAttribs method.
     *
     * @param  int   $index           token index
     * @param  array $tokenAttribsMap token to attribute name map
     * @param  array $attribs         array of token attributes
     * @return array array of grabbed attributes
     */
    public function grabAttribsBeforeToken($index, $tokenAttribsMap, $attribs)
    {
        while (true) {
            $token = $this[--$index];

            if (!$token->isArray()) {
                if (in_array($token->content, array('{', '}', '(', ')', ), true)) {
                    break;
                }

                continue;
            }

            // if token is attribute
            if (array_key_exists($token->id, $tokenAttribsMap)) {
                // set token attribute if token map defines attribute name for token
                if ($tokenAttribsMap[$token->id]) {
                    $attribs[$tokenAttribsMap[$token->id]] = clone $token;
                }

                // clear the token and whitespaces after it
                $this[$index]->clear();
                $this[$index + 1]->clear();

                continue;
            }

            if ($token->isGivenKind(array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, ))) {
                continue;
            }

            break;
        }

        return $attribs;
    }

    /**
     * Insert new Token inside collection.
     *
     * @param int           $index start inserting index
     * @param Token|Token[] $items tokens to insert
     */
    public function insertAt($key, $items)
    {
        $items = is_array($items) ? $items : array($items, );
        $itemsCnt = count($items);
        $oldSize = count($this);

        $this->setSize($oldSize + $itemsCnt);

        for ($i = $oldSize + $itemsCnt - 1; $i >= $key; --$i) {
            $this[$i] = isset($this[$i - $itemsCnt]) ? $this[$i - $itemsCnt] : new Token('');
        }

        for ($i = 0; $i < $itemsCnt; ++$i) {
            $this[$i + $key] = $items[$i];
        }
    }

    /**
     * Removes all the leading whitespace.
     *
     * @param int $index
     */
    public function removeLeadingWhitespace($index)
    {
        if (isset($this[$index - 1]) && $this[$index - 1]->isWhitespace()) {
            $this[$index - 1]->clear();
        }
    }

    /**
     * Removes all the trailing whitespace.
     *
     * @param int $index
     */
    public function removeTrailingWhitespace($index)
    {
        if (isset($this[$index + 1]) && $this[$index + 1]->isWhitespace()) {
            $this[$index + 1]->clear();
        }
    }

    /**
     * Set code. Clear all current content and replace it by new Token items generated from code directly.
     *
     * @param string $code PHP code
     */
    public function setCode($code)
    {
        // clear memory
        $this->setSize(0);

        $tokens = token_get_all($code);
        $this->setSize(count($tokens));

        foreach ($tokens as $index => $token) {
            $this[$index] = new Token($token);
        }

        $this->rewind();
        $this->changeCodeHash(crc32($code));
    }

    /**
     * Clone tokens collection.
     */
    public function __clone()
    {
        foreach ($this as $key => $val) {
            $this[$key] = clone $val;
        }
    }
}
