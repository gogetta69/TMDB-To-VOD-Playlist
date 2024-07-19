<?php

class JavaScriptUnpacker
{
    protected $alphabet = array(
        52 => '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOP',
        54 => '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQR',
        62 => '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
        95 => ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\]^_`abcdefghijklmnopqrstuvwxyz{|}~'
    );

    private $base;
    private $map;

    public function unpack($source, $dynamicHeader = true)
    {
        if (! $this->isPacked($source, $dynamicHeader)) {
            return $source;
        }

        preg_match("/}\('(.*)',\s*(\d+),\s*(\d+),\s*'(.*?)'\.split\('\|'\)/", $source, $match);
        $payload = $match[1];
        $this->base = (int) $match[2];
        $count = (int) $match[3];
        $this->map = explode('|', $match[4]);

        if ($count != count($this->map)) {
            return $source;
        }

        $result = preg_replace_callback('#\b\w+\b#', array($this, 'lookup'), $payload);

        $result = strtr($result, array('\\' => ''));

        return $result;
    }

    public function isPacked($source, $dynamicHeader = true)
    {
        $header = $dynamicHeader ? '\w+,\w+,\w+,\w+,\w+,\w+' : 'p,a,c,k,e,[rd]';

        $source = strtr($source, array(' ' => ''));

        return (bool) preg_match('#^eval\(function\('.$header.'\){#i', trim($source));
    }

    protected function lookup($match)
    {
        $word = $match[0];
        $unbased = $this->map[$this->unbase($word, $this->base)];

        return $unbased ? $unbased : $word;
    }

    protected function unbase($value, $base)
    {
        if (2 <= $base && $base <= 36) {
            return intval($value, $base);
        }

        static $dict = array();
        $selector = $this->getSelector($base);

        if (empty($dict[$selector])) {
            $dict[$selector] = array_flip(str_split($this->alphabet[$selector]));
        }

        $result = 0;
        $array = array_reverse(str_split($value));
        for ($i = 0, $count = count($array); $i < $count; $i++) {
            $cipher = $array[$i];
            $result += pow($base, $i) * $dict[$selector][$cipher];
        }

        return $result;
    }

    protected function getSelector($base)
    {
        if ($base > 62) {
            return 95;
        }
        if ($base > 54) {
            return 62;
        }
        if ($base > 52) {
            return 54;
        }

        return 52;
    }
}

?>