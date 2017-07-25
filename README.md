# slbb-to-bb-transpiler
Sexps-based Language to BlitzBasic transpiler

This project is started as an experiment to provide more "convenient" way to use Blitz3D abilities.
Instead of directly using Blitz3D Basic dialect this transpiler allows you to write program in LISP-like language (*very* limited of course). It makes possible adding virtual methods, and another syntax sugar.

## Syntax, shortly
- Module declaration, must be the topmost expression:
```lisp
(module my.useful.module (exportedA exportedB exportedC))
```
Here a module named `my.useful.module` is declared along with symbols (`exportedA`, `exportedB`, `exportedC`) that are exported from it. Functions and Types can be exported only.

- Import declarations followed by module declaration have the form:
```lisp
(use required.module.Action)
(use required.module.Action ActionAlias)
```
By default imported symbol is available by the last part of the full path,
but if an alias provided then it will be used instead.

- Variable declaration and/or assignment:
```lisp
(set $varname ::int (+ 3 5))
```
Variable `$varname` declared having `int` type and assigned value of the expression.
Basic primitive types are `int` (integer), `str` (string), `float` (really b3d single float), `bool` (really integer),
`ptr` (for handles, also integer).

- Conditional execution:
```lisp
(if (> $a $b) ((print "$a is greater than $b")) (< $x $y) ((print "...")) else (#|...|#))
```
The pattern is `(if <cond0> (<actions0>...) <cond1> (<actions1>...) ... else <condE> (<actionsE>...))`


```lisp
#|
	Exapmle program shown here just to demonstrate the syntax
|#

(module application.launcher (run check))

(use (Direct Buffer Cube Entity Camera graphics3D getKey print))

(function run ::void (
	(graphics3D 1024 768)
	(Buffer.set* (Buffer.back*))
	
	(check)
	
	(set $a (aux "some" 20))
	
	(if (> 5 3) (
		(set $mystr ::str "Greater")
		(print $mystr)
	) 
	$b () 
	else (
		(set $flags ::bool false)
	))
	
	(set $counter ::int 10)
	(while (> $counter 0) (
		(set $counter (- $counter 1))
		(if (> 10 20) (
			(break)
		))
	))
	
	(forstep $counter ::int 0 20 1 (
		(print $counter)
	))
	
	(set $cube ::ref (Cube.create*))
	(Entity.turn* $cube 0.1 0.1 0.2)
	
	(set $camera ::ref (Camera.create*))
	(Camera.*zoom $camera (getKey))
))

(function aux ::int $who ::str $count ::int (
	(set $a (- $count))
	(return (+ $who $count))
))

(function check ::bool (
	(return true)
))

```

