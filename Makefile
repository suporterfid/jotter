.PHONY: up down test e2e release

up:
	./scripts/jt.sh up

down:
	./scripts/jt.sh down

test:
	./scripts/jt.sh test

e2e:
	./scripts/jt.sh e2e

release:
	./scripts/jt.sh release
