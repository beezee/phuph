<?php

declare(strict_types=1);

namespace phuph\test;

use Eris\Generator;
use phuph;
use Widmogrod\Functional as wf;
use Widmogrod\Monad\Maybe as Maybe;

class PhuphTest extends \PHPUnit\Framework\TestCase
{

  use \Eris\TestTrait;

  public function testMatchForward()
  {
    // autoload is really stupid
    $_ = new phuph\Phuph();
    $this->forAll(Generator\string())
      ->disableShrinking()
      ->then(function($s) {
        $ei = max(strlen($s) - 1, 0);
        $this->assertTrue(array_reduce(range(0, $ei), function($a, $i) use($s, $ei) {
          return $a && array_reduce(range($i, $ei),
            function ($a, $i2) use ($s, $i) {
              $test = substr($s, $i, $i2 - $i);
              return $a && ("" == $test || 
                phuph\matchForward($s, $i, $test)->extract() ==
                  [$i - 1, $i + strlen($test)]);
            }, true);
        }, true));
      });
  }
}
