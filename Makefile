# Makefile for hermiq development

# Create a relative symlink in the parent directory so Nextcloud can find the
# app by its ID (hermiq) even though the repo is cloned as hermiq.
# Nextcloud requires the directory name to match the <id> in appinfo/info.xml.
dev-link:
	@if [ -L ../hermiq ]; then \
		echo "Symlink ../hermiq already exists."; \
	else \
		ln -s hermiq ../hermiq && \
		echo "Created symlink: apps-extra/hermiq -> hermiq"; \
	fi

dev-unlink:
	@if [ -L ../hermiq ]; then \
		rm ../hermiq && echo "Removed symlink ../hermiq"; \
	else \
		echo "No symlink found at ../hermiq."; \
	fi

.PHONY: dev-link dev-unlink
