import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const migration = readFileSync(new URL('../migrations/001_initial_schema.sql', import.meta.url), 'utf8');
const entrypoint = readFileSync(new URL('../docker/entrypoint.sh', import.meta.url), 'utf8');
const worker = readFileSync(new URL('../worker/server.mjs', import.meta.url), 'utf8');

const requiredTables = [
  'users', 'api_keys', 'api_request_logs', 'campaigns', 'chatbot_rules', 'contacts',
  'login_attempts', 'message_logs', 'message_queue', 'password_resets',
  'webhooks', 'webhook_deliveries', 'whatsapp_sessions'
];

test('repeatable initial migration creates every current application table without destructive SQL', () => {
  for (const table of requiredTables) {
    assert.ok(migration.includes('CREATE TABLE IF NOT EXISTS `' + table + '`'), `missing table ${table}`);
  }
  const sqlWithoutComments = migration.replace(/^\s*--.*$/gm, '');
  assert.doesNotMatch(sqlWithoutComments, /\b(DROP|TRUNCATE|DELETE FROM|INSERT INTO)\b/i);
  assert.match(migration, /idx_message_queue_worker/);
  assert.match(migration, /idx_message_queue_user_created/);
  assert.match(migration, /idx_message_queue_api_key_created/);
});

test('container migration runs before Supervisor can start workers', () => {
  assert.ok(entrypoint.indexOf('php /var/www/html/scripts/migrate.php') < entrypoint.indexOf('exec /usr/bin/supervisord'));
  assert.match(entrypoint, /^set -e/m);
});

test('TiDB TLS verification is enabled and failed schema startup prevents queue polling', () => {
  assert.match(worker, /rejectUnauthorized:\s*true/);
  assert.doesNotMatch(worker, /rejectUnauthorized:\s*false/);
  assert.match(worker, /Worker startup blocked; queue processor was not started/);
  assert.match(worker, /setInterval\(processQueueOnce, QUEUE_POLL_MS\)/);
});
