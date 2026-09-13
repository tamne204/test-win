.PHONY: all windows darwin clean test

DIST_DIR := ../../dist/native_root

all: windows darwin

windows:
	@mkdir -p $(DIST_DIR)
	GOOS=windows GOARCH=amd64 go build -ldflags "-s -w -H=windowsgui" -o "$(DIST_DIR)/2TOOLNE AutoEdit.exe" .
	@file "$(DIST_DIR)/2TOOLNE AutoEdit.exe"

darwin:
	@mkdir -p $(DIST_DIR)
	go build -ldflags "-s -w" -o "$(DIST_DIR)/2TOOLNE AutoEdit" .
	@chmod +x "$(DIST_DIR)/2TOOLNE AutoEdit"
	@file "$(DIST_DIR)/2TOOLNE AutoEdit"

test: darwin
	@echo "Testing native root verifier..."
	"$(DIST_DIR)/2TOOLNE AutoEdit" --verify-only --resources-dir "../resources" --headless
	@echo "Test passed!"

clean:
	rm -rf $(DIST_DIR)
