# phuph

phuph is a simple command line utility for PHP that reads Markdown files and 
interprets PHP code in `phuph` sheds, allowing you to write documentation
that is evaluated as part of your build.

It is a direct (as possible) port of Rob Norris' [sbt-tut](https://github.com/tpolecat/tut)
for Scala.

It's just PHP that generates documentation for PHP by evaluating the PHP in said documentation,
which was used to generate the documentation for that PHP. And a monad is a monoid
in the category of endofunctors.

## Installation And Use

    composer g require beezee/phuph

Make sure that `~/.composer/vendor/bin` is on your PATH.

This allows you to target specially formatted markdown files for document generation with the phuph
command. If you have your markdown in the file `README.phuph`, you would run

    phuph README.phuph > README.MD

to generate an evaluated version, with the output of your code blocks interleaved with
their definitions.

## Example

Given the following file `README.phuph`

````
##### This is an example

Here is some regular markdown content

```phuph

function example($i) {
  return ['foo' => (1 * $i), 'bar' => (2 * $i)];
}

repl{ example(7); }

repl{ example(9); }
phuph```

And here's the end
````

`phuph README.phuph` would output the following:

```` 
#### This is an example

Here is some regular markdown content

```php

function example($i) {
  return ['foo' => (1 * $i), 'bar' => (2 * $i)];
}

php>  example(7); 
/* Array
(
    [foo] => 7
    [bar] => 14
)
 */

php>  example(9); 
/* Array
(
    [foo] => 9
    [bar] => 18
)
 */
```

And here's the end
````

Which appears as below.

___
#### This is an example

Here is some regular markdown content

```php

function example($i) {
  return ['foo' => (1 * $i), 'bar' => (2 * $i)];
}

php>  example(7); 
/* Array
(
    [foo] => 7
    [bar] => 14
)
 */

php>  example(9); 
/* Array
(
    [foo] => 9
    [bar] => 18
)
 */
```

And here's the end

---

## Basic Usage

### phuph blocks

Code inside phuph blocks will be evaluated silently, and the code
will be printed inside php syntax highlighted code blocks. For example:

````
```phuph
function print_and_return($i) {
  echo $i;
  return $i;
}

print_and_return(3);
phuph```
````



The above will not echo `3`, but the code as written will print
with php syntax highlighting, and the function print_and_return will be 
available for use in later repl expressions, as seen below.

Context from separate phuph blocks are shared, so functions and classes defined
in earlier blocks will be available to later blocks.

### repl expressions

Within a phuph block, repl expressions can be used to include the result of 
evaluating an expression immediately following it's definition. For example,
given the function `print_and_return` defined by our prior phuph block above,
the following:

````
```phuph
repl{ print_and_return(3); }
phuph```
````

would be output as:

```php
php>  print_and_return(3); 
/* 3 */
```

repl expressions _must_ be single expressions, and _must_ have a returnable value.
This is due to the behavior of PHP's `eval` function. Contents within a repl
expression must be used to form a return statement before being evaluated in order
to capture the resulting value and include it in the output.

### Modifiers

In addition to basic phuph blocks, two variations exist.

  * plaincode - used to escape all phuph operators within a region of content
  * phuphsilent - used to evaluate PHP for use in later phuph blocks, without displaying the evaluated code

In addition to the repl expression, one variation exists.

  * escape - used in place of repl within a phuph block to include code that should be displayed but not evaluated.

Examples of all of the above can be found in the 
[file that is used to generate this README](README.phuph)


### Contributing

If you are getting value out of phuph, that's awesome. I know that the Scala version has
been a staple for me, and while feature parity is not something I paid much mind to,
there\'s enough in here to allow me to use phuph to generate it\'s own documentation.
which was a little hairier than I expected.

The coding style is heavily functional, with little ceremony. This means that fixes and
feature additions are generally possible with minimal code changes, but finding the right
code to change takes some careful thought. This is by design.

With that being said, pull requests and issues are welcome.
