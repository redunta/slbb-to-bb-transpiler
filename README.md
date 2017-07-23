# slbb-to-bb-transpiler
Sexps-based Language to BlitzBasic transpiler

This project is started as an experiment to provide more "convenient" way to use Blitz3D abilities.
Instead of directly using Blitz3D Basic dialect this transpiler allows you to write program in LISP-like language (*very* limited of course). It makes possible adding virtual methods, and another syntax sugar.

```lisp
(@module application.launcher (run check))

(@use some.other.feature.Foo)

(@func run::@void (
	(graphics3D 1024 768)
	(Buffer.set* (Buffer.back*))
	
	(@let $myvar::%Foo 10)
	
	(Foo $myvar)
	
	(check)
	
	(@let $a (aux "some"))
	
	(@let $cube::@ref (Cube.create* "hi" 2.5 $myvar))
	(Entity.turn* $cube 0.1 0.1 0.2)
	
	(@let $camera::@ref (Camera.create*))
	(Camera.*zoom $camera (getKey))
))

(@func aux::@int $who::@str $count::@int (
	(@ret (Sum $who $count))
))

(@func check::@bool (
	(@ret @true)
))
```

