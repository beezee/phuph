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
        Generator\elements(["nothing", "before", "after", "both"]),
        Generator\int(), Generator\int(),
        Generator\elements(array_values(phuph\actions())),
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
        Generator\elements(["'", '"', "{", "}"]),
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
      ->disableShrinking()
      ->then(function($pre, $n, $post) {
        $s = array_reduce(range(1, $n), function($a, $_) {
          return $a . "\\";
        }, $pre . "1") . $post;
        $r = T\trampoline(phuph\escapedRec, strlen($pre) + $n, $s, false);
        $this->assertTrue($r == (($n % 2) == 1));
      });
  }
}
