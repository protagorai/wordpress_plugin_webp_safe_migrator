/**
 * JS tests for admin/js/admin.js.
 *
 * NOTE: admin.js is currently NOT enqueued by the plugin and the AJAX actions it
 * calls are not registered (see review.md CRIT-2) — these tests exercise its pure
 * UI logic in a jsdom + jQuery sandbox so it can still be measured and so the code
 * is ready to be wired up. Run via: npm run coverage
 */
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { JSDOM } = require('jsdom');

const ADMIN_JS = fs.readFileSync(
  path.join(__dirname, '..', '..', 'admin', 'js', 'admin.js'),
  'utf8'
);

function bootAdmin(bodyHtml = '') {
  const dom = new JSDOM(
    `<!DOCTYPE html><body><div class="wrap"><h1>WebP</h1></div>${bodyHtml}</body>`,
    { url: 'http://localhost/wp-admin/' }
  );
  const { window } = dom;
  const jQuery = require('jquery')(window);

  // Neutralise network so init()'s ajax calls don't throw in the sandbox.
  jQuery.ajax = () => ({ done() { return this; }, fail() { return this; } });
  jQuery.post = () => ({ done() { return this; }, fail() { return this; } });

  const context = {
    window,
    document: window.document,
    navigator: window.navigator,
    jQuery,
    ajaxurl: '/wp-admin/admin-ajax.php',
    webpMigratorAdmin: { nonce: 'test-nonce' },
    setInterval, clearInterval, setTimeout, clearTimeout,
    console,
    alert: () => {},
    confirm: () => true,
    location: window.location,
    Date,
  };
  vm.createContext(context);
  vm.runInContext(ADMIN_JS, context);
  return { window, $: jQuery, M: context.window.WebPMigrator };
}

test('exposes WebPMigrator on window', () => {
  const { M } = bootAdmin();
  assert.ok(M, 'WebPMigrator should be exported');
  assert.equal(typeof M.updateProgressBar, 'function');
});

test('updateProgressBar computes percentage and updates the DOM', () => {
  const { $, M } = bootAdmin('<div id="progress-container"></div>');
  M.initProgressBar();
  M.updateProgressBar(5, 10, 'Working');
  assert.equal($('.progress-percentage').text(), '50%');
  assert.equal($('.progress-current').text(), '5');
  assert.equal($('.progress-total').text(), '10');
  assert.equal($('.progress-message').text(), 'Working');
});

test('getConversionOptions reads form inputs with sane defaults', () => {
  const { M } = bootAdmin(
    '<input id="quality" value="42"><select id="batch-size"><option value="7" selected>7</option></select>'
  );
  const opts = M.getConversionOptions();
  assert.equal(opts.quality, 42);
  assert.equal(opts.batch_size, 7);
});

test('showNotice renders a dismissible notice', () => {
  const { $, M } = bootAdmin();
  M.showNotice('Hello', 'success');
  assert.equal($('.webp-notice').length, 1);
  assert.match($('.webp-notice').attr('class'), /notice-success/);
});

test('updateSelectionCount reflects checked boxes', () => {
  const { $, M } = bootAdmin(
    '<span id="selection-count"></span>' +
    '<input type="checkbox" class="attachment-checkbox" checked>' +
    '<input type="checkbox" class="attachment-checkbox">'
  );
  M.updateSelectionCount();
  assert.equal($('#selection-count').text(), '1 of 2 selected');
});
