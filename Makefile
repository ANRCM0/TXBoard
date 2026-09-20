.PHONY: verify web api node

verify: web api node

web:
	npm run verify:web

api:
	composer test --working-dir=api

node:
	$(MAKE) -C node test
