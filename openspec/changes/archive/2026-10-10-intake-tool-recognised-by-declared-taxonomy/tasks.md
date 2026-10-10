# Tasks

- [x] 1.1 `IntakeToolGrant::createsOnly()` requires `scope: create` and `action: create` plus the write classification; the id-suffix rule is removed (lib/Service/Intake/IntakeToolGrant.php; IntakeToolGrantTest::testOnlyAMarkedToolDeclaringCreateTwiceIsAnIntakeTool)
- [x] 1.2 `IntakeToolGrant::idOf()` reads `mcpId`, then `name`, then `id`; `permits()` accepts the dotted id and the safe alias (IntakeToolGrantTest::testTheDottedIdAndTheSafeAliasAreBothPermitted, testIdOfPrefersTheDottedId)
- [x] 1.3 An unqualified tool is refused before the owning app is called (IntakeToolGrantTest::testAnUnqualifiedToolIsRefusedBeforeTheAppIsCalled)
- [x] 1.4 `IntakeServiceTest`'s catalogue declares the create taxonomy on its intake tool
- [x] 1.5 `IntakeToolGrant::call()` treats `isError: true` inside the result as a refusal: OpenRegister's bridge folds a thrown refusal into the result and the facade reports the call as successful (IntakeToolGrantTest::testARefusalFoldedIntoTheResultIsStillARefusal)
