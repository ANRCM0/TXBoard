.PHONY: verify web api

verify: web api

web:
	npm run verify:web

api:
	composer test --working-dir=api
