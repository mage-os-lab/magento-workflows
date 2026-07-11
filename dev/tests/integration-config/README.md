# Integration-test lane configuration

Support files for the Magento integration-test lane
([docs/20-integration-test-plan.md](../../../docs/20-integration-test-plan.md)).
Suites live at `src/<module>/Test/Integration/…` and run inside a real
Magento install's `dev/tests/integration` framework — never under the
standalone shim runner (which discovers `Test/Unit` only).

The `integration-test-extension` job in `.github/workflows/check-extension.yml`:

1. installs the monorepo packages into a Magento project (same wildcard
   path-repo mechanics as the unit/compile jobs) plus the real
   `mage-os/mageos-async-events`;
2. copies `install-config-mysql.php` from here into
   `<magento>/dev/tests/integration/etc/` (MySQL + OpenSearch service
   containers; **no amqp** — the queue framework falls back to the `db`
   transport so consumers run in-process);
3. injects a `MageOS_Workflows_Integration` testsuite pointing at
   `vendor/mage-os/workflows*/Test/Integration` into Magento's own
   `dev/tests/integration/phpunit.xml.dist` (version-correct for each
   matrix line) and drops the allure extension;
4. stages the monorepo `spec/` dir at `<magento>/vendor/spec` for suites
   that read published fixtures;
5. runs `vendor/bin/phpunit … --testsuite MageOS_Workflows_Integration`.

To run locally against your own Magento dev install: perform steps 2–4 by
hand with your DB credentials, then run step 5 from
`<magento>/dev/tests/integration`.
