# slbb-to-bb-transpiler
Sexps-based Language to BlitzBasic transpiler

This project is started as an experiment to provide more "convenient" way to use Blitz3D abilities.
Instead of directly using Blitz3D Basic dialect this transpiler allows you to write program in LISP-like language (*very* limited of course). It makes possible adding virtual methods, and another syntax sugar.

## Syntax, shortly
- Module declaration, must be the topmost expression:
```lisp
('module my.useful.module (exportedA exportedB exportedC))
```
Here a module named `my.useful.module` is declared along with symbols (`exportedA`, `exportedB`, `exportedC`) that are exported from it. Functions, methods, types, constants can be exported.

- Import declarations followed by module declaration have the form:
```lisp
('use required.module.Action)
('use required.module.Action ActionAlias)
```
By default imported symbol is available by the last part of the full path,
but if an alias provided then it will be used instead.

- Variable declaration and/or assignment:
```lisp
('set $varname ::int (+ 3 5))
```
Variable `$varname` declared having `int` type and assigned value of the expression.
Basic primitive types are `int` (integer), `str` (string), `float` (really b3d single float), `bool` (really integer),
`ptr` (for handles, also integer).

- Conditional execution:
```lisp
('if (> $a $b) ((print "$a is greater than $b")) (< $x $y) ((print "...")) 'else (#|...|#))
```
The pattern is `('if <cond0> (<actions0>...) <cond1> (<actions1>...) ... 'else <condE> (<actionsE>...))`

- Record type declaration:
```lisp
('type Box 
	width ::int
	height ::int
	depth ::int
)
```

- Function definition
```lisp
('function duplicate ::int $a ::int (
	('return (* $a 2))
))
```

- Method (virtual function prototype) declaration:
```lisp
('method ABox.getVolume ::int $box ::ptr)
```
Method declaration is similar to how function is defined but has no function body and works as accumulator for possible implementations which are to be selected at runtime (during method call) by the first argument type.

- Function as method implementation.
After a function body a method can be referenced to make the function one of possible method implementations for given first argument type.

```lisp
#|
	Example program shown here just to demonstrate the syntax
|#

('module application.launcher (run check))

('use test-mod.here.CustomFunc)
('use test-mod.here.AbstractBox)
('use test-mod.here.Box)
('use &Buffer RawBuffer)
('use (&Cube &Entity &Camera graphics3D getKey print))

('const MY_VAR ::int 10)

(run)

('function run ::void (
	(graphics3D 1024 768)
	(RawBuffer.set (RawBuffer.back))
	
	(print MY_VAR)
	
	(CustomFunc)
	(check)
	
	('set $box ::ptr (Box.create))
	(AbstractBox.setSize $box 10 20 30)
	(print (AbstractBox.getVolume $box))
	(AbstractBox.free $box)
	
	('set $a (aux "some" 20))
	
	('if (> 5 3) (
		('set $mystr ::str "Greater")
		(print $mystr)
	) 
	$b () 
	'else (
		('set $flags ::bool 'false)
	))
	
	('set $counter ::int 10)
	('while (> $counter 0) (
		('set $counter (- $counter 1))
		('if (> 10 20) (
			('break)
		))
	))
	
	('forstep $counter ::int 0 20 1 (
		(print $counter)
	))
	
	('set $cube ::ref (Cube.create))
	(Entity.turn $cube 0.1 0.1 0.2)
	
	('set $camera ::ref (Camera.create))
	(Camera.&zoom $camera (getKey))
))

('function aux ::int $who ::str $count ::int (
	('set $a (- $count))
	('return (+ $who $count))
))

('function check ::bool (
	('return 'true)
))

```

