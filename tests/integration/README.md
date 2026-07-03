# Integration Tests (Newman / Postman)

This directory contains Newman/Postman test collections for API integration testing.

## Enabling in CI

In `.github/workflows/code-quality.yml`, set:

```yaml
enable-newman: true
```

## Running locally

```bash
npm install -g newman
newman run tests/integration/hermiq.postman_collection.json \
  --env-var base_url=http://nextcloud.local \
  --env-var admin_user=admin \
  --env-var admin_password=admin
```

The variable names (`base_url`, `admin_user`, `admin_password`) match what the CI workflow passes.
In CI the server runs on `http://localhost:8080` (PHP built-in server); locally use `http://nextcloud.local`.

## Structure

Add your Postman collection JSON files to this directory. The CI runner picks up
all `*.postman_collection.json` files automatically.
