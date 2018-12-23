<?php

declare(strict_types=1);

namespace phuph;

use FunctionalPHP\Trampoline as T;
use Widmogrod\Functional as wf;
use Widmogrod\Monad\Maybe as Maybe;

// string -> int -> string -> maybe (int, int)
function matchForward(string $file, int $ix, string $test): Maybe\Maybe {
  $len = strlen($test);
  return substr($file, $ix, $len) == $test
    ? Maybe\just([$ix - 1, $ix + $len]) : Maybe\nothing();
}

// actiontest :: [test: string, close: context, open: context, explicit: o|c (t|f)]]
// actions :: [actiontest]
function actions(): array {
  // This is used greedily, so these need to be ordered descending by
  // test string length
  return [
    ["```phuph", "text", "code", true],
    ["repl{", "code", "repl", true],
    ["```", "code", "text", false],
    ["}", "repl", "code", false],
  ];
}

function filterMaybe(callable $p, Maybe\Maybe $m): Maybe\Maybe {
  return $m->bind(function($x) use ($p) {
    return $p($x) ? Maybe\just($x) : Maybe\nothing();
  });
}

// TODO - this is partial, which is stupid
// maybe once php-data is documented and refined it can help here
function badSyntax(string $close, string $open): array {
  return filterMaybe(
      function($arr) { return sizeof($arr) > 0; },
      Maybe\just(array_values(array_filter(actions(), function($e) use ($close, $open) {
        return $e[1] == $close && $e[2] == $open;
      }))))
    ->map(function($a) { return [$a[0][0], $a[0][3]]; })
    ->reduce(function($a, $e) { return $e; }, ["error", true]);
}

// param actiontest :: actiontest
// returns Maybe (matchString, (context, action, ix), (context, action, ix))
function testAction(array $actiontest, int $ix, string $file): Maybe\Maybe {
  return matchForward($file, $ix, $actiontest[0])
    ->map(function($oc) use($actiontest) {
      return [
        $actiontest[0],
        [$actiontest[1], false, $oc[0]], 
        [$actiontest[2], true, $oc[1]]];
    });
}

function orElse(Maybe\Maybe $m1, Maybe\Maybe $m2): Maybe\Maybe {
  return $m1->reduce(function($a, $e) { return Maybe\just($e); }, $m2);
}

function testActions(int $ix, string $file): Maybe\Maybe {
  return array_reduce(actions(), function($a, $e) use ($ix, $file) {
    return orElse($a, testAction($e, $ix, $file));
  }, Maybe\nothing());
}

// specialContexts: [inQuote: boolean, inDoubleQuote: boolean, inBrace: int]
// inContext :: char -> specialContexts -> specialContexts
function specialContext(string $char, array $c): array {
  if ($char == "'" && !$c[1]) return [!$c[0], $c[1], $c[2]];
  if ($char == '"' && !$c[0]) return [$c[0], !$c[1], $c[2]];
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

const parseRec = '\phuph\Phuph::parseRec';
const processRec = '\phuph\Phuph::processRec';

class Phuph {

  // context: string = "code" | "repl" | "text"
  // action: open | close = t|f
  // param acc: [(context, action, ix)]
  // param sc: specialContexts
  // return [(context, action, ix)]
  static function parseRec(int $ix, string $file, array $sc, array $acc) {
    // recurse inside string or opened braces
    if (strlen($file) > $ix && specialContextZero() != $sc) 
      return T\bounce(parseRec, 
        $ix + 1, $file, specialContext(substr($file, $ix, 1), $sc), $acc);
    list($nAcc, $nSc) = testActions($ix, $file)->reduce(
      function($a, $e) use ($ix, $file, $sc) {
        return [wf\push_($a[0], tail($e)), $sc];
      }, [$acc, 
          (last($acc)[0] == "text")
            ? $sc
            : specialContext(substr($file, $ix, 1), $sc)]);
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
          : (($e[0] == $o[0]) 
            ? [Maybe\nothing(), 
               wf\push_(
                $acc, 
                [[$e[0], substr($file, $o[2], $e[2] + 1 - $o[2])]])]
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
      if ($e[0] == "text") $a .= ($a == "") ? $e[1] : "``` \n" . $e[1];
      if ($e[0] == "code") {
        try {
          $c = trim($e[1]);
          eval($c);
          $a .= (($processed[$i - 1][0] == "text") ? "\n```php\n" : "") . $c . "\n";
        } catch (Throwable $err) {
          $l = $err->getLine() + sizeof(explode($a, "\n"));
          throw new Exception("Line $l " . $err->getMessage());
        }
      }
      if ($e[0] == "repl") {
        try {
          $c = trim($e[1]);
          $r = eval("return " . $c);
          $a .= "> " . $c . "\n/* " . print_r($r, true) . " */ \n";
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
