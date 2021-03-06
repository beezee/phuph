<?php

declare(strict_types=1);

namespace phuph\test;

use Eris\Generator;
use FunctionalPHP\Trampoline as T;
use phuph;
use Widmogrod\Functional as wf;
use Widmogrod\Monad\Maybe as Maybe;

class PhuphTest extends \PHPUnit\Framework\TestCase
{

  use \Eris\TestTrait;

  // autoload is really stupid
  function __construct() {
    new phuph\Phuph();
    parent::__construct();
  }

  public function testMatchForward()
  {
    $this->forAll(Generator\string(), Generator\neg())
      ->then(function($s, $ni) {
        $ei = max(strlen($s) - 1, 0);
        $this->assertTrue(array_reduce(range(0, $ei), function($a, $i) use($s, $ei) {
          return $a && array_reduce(range($i, $ei),
            function ($a, $i2) use ($s, $i) {
              $test = substr($s, $i, $i2 - $i);
              $r = phuph\matchBoundaries($s, $i, $test);
              return $a && phuph\fold($r, [
                "nothing" => function() use ($test, $s) { 
                  return "" == $test || $test == $s; },
                "before" => function($r) use($s, $i, $i2) { 
                  return strlen($s) == $i2 && ($i - 1) == $r; },
                "after" => function($r) use($test, $i) {
                  return 0 == $i && strlen($test) == $r; },
                "both" => function($r) use ($test, $i) {
                  return [$i - 1, $i + strlen($test)] == $r; }]);
            }, true);
        }, true) 
          && ["nothing", null] == 
              phuph\matchBoundaries($s, $ni, substr($s, 0, max(0, strlen($s) - 1))));
      });
  }

  public function testHandleBoundaries()
  {
    $this->forAll(
        Generator\elements("nothing", "before", "after", "both"),
        Generator\int(), Generator\int(),
        Generator\elements(...array_values(phuph\actions())),
        Generator\string())
      ->then(function($b, $oi, $ci, $at, $f) {
        $bs = [$b, phuph\fold([$b, null],
          ["nothing" => wf\identity,
           "before" => wf\constt($ci),
           "after" => wf\constt($oi),
           "both" => wf\constt([$ci, $oi])])];
        $r = phuph\handleBoundaries($f, $at, $bs);
        $rv = $r->extract();
        $this->assertTrue(
          ((Maybe\nothing() == $r && "nothing" == $b) ||
           (($rv[0] == $at[0] && [$at[1], false] == [$rv[1][0], $rv[1][1]]
            && [$at[2], true] == [$rv[2][0], $rv[2][1]]) &&
            (("after" == $b && 0 == $rv[1][2] && $oi == $rv[2][2])
             || ("before" == $b && strlen($f) == $rv[2][2] && $ci == $rv[1][2])
             || ("both" == $b && $ci == $rv[1][2] && $oi == $rv[2][2])))));
      });
  }

  public function testSpecialContext() {
    $this->forAll(
        Generator\elements("'", '"', "{", "}"),
        Generator\bool(), Generator\bool(),
        Generator\int(), Generator\bool())
      ->then(function($c, $sq, $dq, $b, $e) {
        $sc = phuph\specialContext($c, [$sq, $dq, $b], $e);
        $this->assertTrue(
          (("'" == $c && $sc[0] != $sq && [false, false, $b] == [$dq, $e, $sc[2]])
           || ('"' == $c && $sc[1] != $dq && [false, false, $b] == [$sq, $e, $sc[2]])
           || ("{" == $c && [false, false] == [$sq, $dq]
               && [false, false, $b + 1] == $sc)
           || ("}" == $c && [false, false] == [$sq, $dq]
               && [false, false, $b - 1] == $sc)
           || ($e && [$sq, $dq] == [$sc[0], $sc[1]])
           || ([$sq, $dq, $b] == $sc)));
    });
  }

  public function testEscapedRec() {
    $this->forAll(
        Generator\string(), Generator\pos(),
        Generator\string())
      ->then(function($pre, $n, $post) {
        $s = array_reduce(range(1, $n), function($a, $_) {
          return $a . "\\";
        }, $pre . "1") . $post;
        $r = T\trampoline(phuph\escapedRec, strlen($pre) + $n, $s, false);
        $this->assertTrue($r == (($n % 2) == 1));
      });
  }

  public function testParseRec() {
    $tokens = array_map(function($a) { return $a[0]; }, array_values(phuph\actions()));
    $this->forAll(
        Generator\string(), Generator\seq(Generator\elements(...$tokens)))
      ->then(function($delim, $ts) {
        // blocks that start and end at 0 or right bound are ignored
        // in processing. this is shitty but not worth fixing right now,
        // so left pad the input string
        $s = "hackycrap" . array_reduce($ts, function($a, $t) use ($delim) {
          return $a . $delim . $t;
        }, $delim) . $delim;
        $r = T\trampoline(phuph\parseRec, 0, $s, phuph\specialContextZero(), []);
        // [acc: [(o, c)], current: o|()]
        $pairs = array_reduce($r, function($a, $e) {
          return $a[1]
            ? [wf\push_($a[0], [[$a[1], $e]]), null]
            : [$a[0], $e];
         }, [[], null])[0];
        $this->assertTrue(array_reduce($pairs, function($a, $e) use ($s) {
          return $a && phuph\action($e[0][0], $e[1][0])->map(function($a) use ($e, $s) {
            return substr($s, $e[0][2] + 1, strlen($a[0])) == $a[0];
          })->extract() ?: false;
        }, true));
        // testing escape characters at this level with unknown inputs would be tedious
        // fall back to old school asserts
        $esc = '```phuph echo "\"repl{ 1; }\"" phuph```';
        $r = T\trampoline(phuph\parseRec, 0, $esc, phuph\specialContextZero(), []);
        $this->assertTrue(array_reduce($r, function($a, $e) {
          return $a && ($e[0] == "code" || $e[0] == "text");
        }, true));
      });
  }

  public function testProcessRec() {
    $tokens = array_map(function($a) { return $a[0]; }, array_values(phuph\actions()));
    $this->forAll(
        Generator\seq(
          Generator\tuple(
            Generator\elements(array_keys(
              T\pool(function(array $a, array $acc) {
                if (sizeof($a) == 0) return $acc;
                $acc[$a[0][1]] = 0;
                return $this(array_slice($a, 1), $acc);
              })(array_values(phuph\actions()), []))),
            Generator\pos())),
        Generator\string())
      ->then(function($os, $s) {
        // acc: [sum: int, [(context, action, ix)]]
        list($l, $parsed) = array_reduce($os, function($a, $e) {
          $ei = $e[1] % 27;
          return [$a[0] + $ei,
            wf\push_($a[1], 
              [[$e[0], true, $a[0]], 
               [$e[0], false, $a[0] + $ei]])];
          }, [0, []]);
        $f = T\pool(function(string $s, int $max, string $acc) {
          if (strlen($acc) > $max) return $acc;
          return $this($s, $max, $acc . $s);
        })("pad" . $s, $l, "");
        $r = T\trampoline(phuph\processRec, 0, $parsed, $f, Maybe\nothing(), []);
        // This is starting to re-implement code under test, but it's not worthless
        $this->assertTrue(T\pool(function(array $r, array $p, bool $a) use ($f) {
          if (sizeof($r) == 0) return $a;
          list($o, $c) = $p;
          list($b) = $r;
          return $this(array_slice($r, 1), array_slice($p, 2),
            $a && substr($f, $o[2], $c[2] - $o[2] + 1) == $b[1] && $b[0] == $c[0]
              && $b[0] == $o[0]);
        })($r, $parsed, true));
        // THIS is cool. recompute the original inputs, solely from the output,
        // and assert that running again is equal to initial output.
        // this means that proccessRec has a true inverse with which it
        // forms an isomorphism
        // acc: [len: int, reInput: [(context, action, ix)]]
        $inverse = array_reduce($r, function($a, $e) {
          return [$a[0] + strlen($e[1]),
            wf\push_($a[1], 
              [[$e[0], true, $a[0]], 
               [$e[0], false, $a[0] + strlen($e[1]) - 1]])];
        }, [0, []]);
        $this->assertTrue(
          T\trampoline(phuph\processRec, 0, $inverse[1], $f, Maybe\nothing(), [])
          == $r
          && $parsed == $inverse[1]);
        $unbalanced = [["code", true, 0], ["code", true, 0]];
        $this->expectException(\Exception::class);
        T\trampoline(phuph\processRec, 0, 
          wf\push_($unbalanced, $parsed), $f, 
          Maybe\nothing(), []);
      });
  }
}
