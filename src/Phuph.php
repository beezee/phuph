<?php

declare(strict_types=1);

namespace phuph;

use FunctionalPHP\Trampoline as T;
use Widmogrod\Functional as wf;
use Widmogrod\Monad\Maybe as Maybe;

// a|b|c... -> (a->e,b->e,c->e...) -> e
function fold(array $sum, array $fns) {
  list($k, $v) = $sum;
  return getIfSet($sum[0], $fns)->reduce(function($_, $fn) use ($v) {
    return function() use ($fn, $v) { return $fn($v); };
  }, function () use ($k) { 
    throw new Exception("No function provided for $k folding folding over sum type");
  })();
}

// string -> int -> string -> nothing|before|after|both
function matchBoundaries(string $file, int $ix, string $test): array { 
  $len = strlen($test);
  $matches = substr($file, $ix, $len) == $test;
  if ($len == 0 || $ix < 0 || !$matches || $file == $test) return ["nothing", null];
  if ($ix == 0) return ["after", $ix + $len];
  if (strlen($file) <= $ix + $len) return ["before", $ix - 1];
  return ["both", [$ix - 1, $ix + $len]];
}

// actiontest :: [test: string, close: context, open: context, explicit: o|c (t|f)]]
// actions :: [actiontest]
function actions(): array {
  // This is used greedily, so these need to be ordered descending by
  // test string length
  return [
    "text->silentcode" => ["```phuphsilent", "text", "silentcode", true],
    "silentcode->text" => ["phuphsilent```", "silentcode", "text", false],
    "text->plaincode" => ["```plaincode", "text", "plaincode", true],
    "plaincode->text" => ["plaincode```", "plaincode", "text", false],
    "text->code" => ["```phuph", "text", "code", true],
    "code->text" => ["phuph```", "code", "text", false],
    "code->escape" => ["escape{", "code", "escape", true],
    "code->repl" => ["repl{", "code", "repl", true],
    "repl->code" => ["}", "repl", "code", false]
  ];
}

const closeSynonyms = [
  'repl' => 'escape'
];

function getIfSet($k, $a): Maybe\Maybe {
  return (isset($a[$k])) ? Maybe\just($a[$k]) : Maybe\nothing();
}

function action($close, $open): Maybe\Maybe {
  return getIfSet("{$close}->{$open}", actions());
}

// param actions: [(open: context, close: context)]
function filterActions(array $actions): array {
  return array_reduce($actions, function($a, $k) {
    return wf\push_($a, action($k[0], $k[1])
      ->map(function ($a) { return [$a]; }) 
      ->extract() ?: []);
  }, []);
}

// TODO - this is partial, which is stupid
// maybe once php-data is documented and refined it can help here
function badSyntax(string $close, string $open): array {
  return action($close, $open)
    ->map(function($a) { return [$a[0], $a[3]]; })
    ->reduce(function($a, $e) { return $e; }, ["error", true]);
}

// param actiontest :: actiontest
// returns Maybe (matchString, (context, action, ix), (context, action, ix))
function testAction(array $actiontest, int $ix, string $file): Maybe\Maybe {
  $ixPair = function($c, $o) use ($actiontest) {
    return [
      $actiontest[0],
      [$actiontest[1], false, $c],
      [$actiontest[2], true, $o]];
  };
  return Maybe\maybeNull(fold(matchBoundaries($file, $ix, $actiontest[0]),
    ["nothing" => wf\constt(null),
     "before" => function($i) use ($file, $ixPair) { return $ixPair($i, strlen($file)); },
     "after" => function($i) use ($ixPair) { return $ixPair(0, $i); },
     "both" => function($oc) use ($ixPair) { return $ixPair($oc[0], $oc[1]); }]));
}

function orElse(Maybe\Maybe $m1, Maybe\Maybe $m2): Maybe\Maybe {
  return $m1->reduce(function($a, $e) { return Maybe\just($e); }, $m2);
}

// param actions: [actiontest]
function testActions(int $ix, string $file, array $actions): Maybe\Maybe {
  return array_reduce($actions, function($a, $e) use ($ix, $file) {
    return orElse($a, testAction($e, $ix, $file));
  }, Maybe\nothing());
}

// specialContexts: [inQuote: boolean, inDoubleQuote: boolean, inBrace: int]
// inContext :: char -> specialContexts -> specialContexts
function specialContext(string $char, array $c, bool $esc): array {
  if ($char == "'" && !$c[1] && !$esc) return [!$c[0], $c[1], $c[2]];
  if ($char == '"' && !$c[0] && !$esc) return [$c[0], !$c[1], $c[2]];
  if ($char == '{' && !($c[0] || $c[1])) return [$c[0], $c[1], $c[2] + 1];
  if ($char == '}' && !($c[0] || $c[1])) return [$c[0], $c[1], $c[2] - 1];
  return $c;
}

function specialContextZero() {
  return [false, false, 0];
}

function last(array $a) {
  $b = $a;
  return array_pop($b);
}

function tail(array $a) {
  $b = $a;
  return wf\tee('array_shift')($b);
}

const escapedRec = '\phuph\Phuph::escapedRec';
const parseRec = '\phuph\Phuph::parseRec';
const processRec = '\phuph\Phuph::processRec';

class Phuph {

  static function escapedRec(int $ix, string $file, bool $esc) {
    if (substr($file, $ix, 1) != '\\') return $esc;
    if ($ix == 0) return !$esc;
    return T\bounce(escapedRec, $ix-1, $file, !$esc);
  }

  // context: string = "escape" | "silentcode" | "plaincode" | "code" | "repl" | "text"
  // action: open | close = t|f
  // param acc: [(context, action, ix)]
  // param sc: specialContexts
  // return [(context, action, ix)]
  static function parseRec(int $ix, string $file, array $sc, array $acc) {
    $isEsc = T\trampoline(escapedRec, $ix-1, $file, false);
    // recurse inside string or opened braces
    if (strlen($file) > $ix && specialContextZero() != $sc) 
      return T\bounce(parseRec, 
        $ix + 1, $file, specialContext(substr($file, $ix, 1), $sc, $isEsc), $acc);
    // only acknowledge close tag when in plaincode
    $actions = (last($acc)[0] == "plaincode") 
      ? filterActions([["plaincode", "text"]]) : array_values(actions());
    // only honor special contexts when outside plaincode or text
    $bSc = (in_array(last($acc)[0], ["text", "plaincode"]))
      ? $sc
      : specialContext(substr($file, $ix, 1), $sc, $isEsc);
    list($nAcc, $nSc) = testActions($ix, $file, $actions)->reduce(
      function($a, $e) use ($ix, $file, $sc) {
        return [wf\push_($a[0], tail($e)), $sc];
      }, [$acc, $bSc]);
    $lastOpen = last($nAcc);
    // base case, end recursion
    if (strlen($file) == $ix)
      return wf\push_($nAcc, [[$lastOpen[0], !$lastOpen[1], strlen($file) - 1]]);
    // recurse either accumulating more context changes or checking for string/opened braces
    return T\bounce(parseRec, max($ix + 1, $lastOpen[2]), $file, $nSc, $nAcc);
  }

  // TODO - this has gotten ugly... coupling of ixs and err is opaque
  static function error(string $file, array $err, int $oix, int $cix) {
    $lineno = sizeof(explode("\n", substr($file, 0, $err[1] ? $oix : $cix)));
    throw new \Exception("Unexpected {$err[0]} at line $lineno");
  }

  // param parsed: [(context, action, ix)]
  // return [(context, text)]
  static function processRec(int $ix, array $parsed, string $file, Maybe\Maybe $open, array $acc) {
    // acc: [r: [snippet], open: Maybe open]
    if ($ix == sizeof($parsed)) return $acc;
    $e = $parsed[$ix];
    // this is dirty but so is the partial function that will use it
    $n = isset($parsed[$ix + 1]) ? $parsed[$ix + 1] : ["end"];
    list($nOpen, $nAcc) = $open->reduce(function($_, $o) use($e, $n, $file, $acc) {
      return function() use ($o, $e, $n, $file, $acc) {
        return $e[1]
          ? static::error($file, ["error", false], $o[2], $e[2])
          : (($e[0] == $o[0] || getIfSet($e[0], closeSynonyms)->extract() == $o[0])
            ? [Maybe\nothing(), 
               wf\push_(
                $acc, 
                [[$o[0], substr($file, $o[2], $e[2] + 1 - $o[2])]])]
            : static::error($file, badSyntax($e[0], $n[0]), $n[2], $e[2]));
      };
    }, function() use($e, $n, $file, $acc) {
      return $e[1]
        ? [Maybe\just($e), $acc]
        : static::error($file, ["error", false], $e[2], $e[2]);
    })();
    return T\bounce(processRec, $ix + 1, $parsed, $file, $nOpen, $nAcc);
  }

  static function buildOutput(array $processed): string {
    // TODO - another great example of the awfulness of no sum types
    ob_start();
    $a = "";
    foreach($processed as $i => $e) {
      if ($e[0] == "text") $a .= 
        (isset($processed[$i - 1]) && $processed[$i - 1][0] != "silentcode")
          ? "```" . $e[1] : $e[1];
      if ($e[0] == "plaincode") $a .= "````" . $e[1] . "`";
      if ($e[0] == "escape") $a .= $e[1];
      if ($e[0] == "silentcode") {
        try {
          eval($e[1]);
        } catch (Throwable $err) {
          $l = $err->getLine() + sizeof(explode($a, "\n"));
          throw new Exception("Line $l " . $err->getMessage());
        }
      }
      if ($e[0] == "code") {
        try {
          eval($e[1]);
          $a .= (($processed[$i - 1][0] == "text") ? "```php" : "") . $e[1];
        } catch (Throwable $err) {
          $l = $err->getLine() + sizeof(explode($a, "\n"));
          throw new Exception("Line $l " . $err->getMessage());
        }
      }
      if ($e[0] == "repl") {
        try {
          $r = eval("return " . $e[1]);
          $a .= "php> " . $e[1] . "\n/* " . print_r($r, true) . " */";
        } catch (Throwable $err) {
          $l = $err->getLine() + $e[2];
          throw new Exception("Line $l " . $err->getMessage());
        }
      }
    };
    ob_get_clean();
    return $a;
  }

  static function parse(string $file) {
    $parsed = T\trampoline(parseRec, 0, $file, specialContextZero(), [["text", true, 0]]);
    $processed = T\trampoline(processRec, 0, $parsed, $file, Maybe\nothing(), []);
    return static::buildOutput($processed);
  }
}
