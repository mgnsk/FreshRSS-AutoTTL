(function () {
	var ROOT_ID = 'freshrss-autottl-configure';

	function refresh(root) {
		var url = root.getAttribute('data-refresh-url');
		if (!url) {
			return Promise.resolve();
		}

		return fetch(url, { credentials: 'same-origin' })
			.then(function (response) { return response.text(); })
			.then(function (html) {
				var temp = document.createElement('div');
				temp.innerHTML = html;
				var fresh = temp.querySelector('#' + ROOT_ID);
				var current = document.getElementById(ROOT_ID);
				if (fresh && current) {
					current.outerHTML = fresh.outerHTML;
				}
			});
	}

	// Each submit here saves the user's *entire* config back (see
	// AutoTTLExtension::handleConfigureAction()/backoffController::toggleAction()),
	// not just the one field being changed - so two of these in flight at once
	// (e.g. rapid-clicking two switches) could race, with whichever save lands
	// last silently clobbering the other's change. Queue them through one
	// promise chain so only one submit+refresh cycle ever runs at a time.
	var queue = Promise.resolve();

	function submitInBackground(url, body, root) {
		queue = queue.then(function () {
			return fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				redirect: 'manual',
				body: body,
			}).then(function (response) {
				// redirect: 'manual' turns a successful save (the server redirects
				// back to the configure page) into an opaque response with status 0
				// and ok:false - that's the success case here, not a failure. Any
				// other non-ok response (CSRF failure, 500, ...) is a real failure.
				return response.type === 'opaqueredirect' || response.ok;
			}, function () {
				return false; // network failure
			}).then(function (saved) {
				// Refresh either way, so the panel reflects whatever actually ended
				// up persisted server-side, even after a failed save.
				return refresh(root).then(function () {
					return saved;
				});
			}).then(function (saved) {
				// openNotification() is FreshRSS core's own toast (p/scripts/main.js),
				// already loaded/initialized on every admin page - reuse it instead of
				// adding our own notification markup/CSS.
				if (typeof openNotification === 'function') {
					openNotification(saved ? 'Saved' : 'Save failed', saved ? 'good' : 'bad');
				}
			});
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-autottl-backoff-toggle]');
		if (!button || !button.form) {
			return;
		}
		var root = button.closest('#' + ROOT_ID);
		if (!root) {
			return;
		}

		event.preventDefault();
		submitInBackground(button.getAttribute('formaction'), new FormData(button.form), root);
	});

	// The Max TTL <select> and Statistics table rows <input> used to auto-submit
	// via an inline onchange="" attribute, but a strict CSP (default-src 'self'
	// with no 'unsafe-inline'/nonce for script-src) silently blocks inline event
	// handlers - see issue #53. Wiring the same behaviour up here instead keeps
	// it CSP-safe, same reasoning as the external stylesheet link above.
	document.addEventListener('change', function (event) {
		var el = event.target;
		if (!el.matches || !el.matches('[data-autottl-live-submit]') || !el.form) {
			return;
		}
		el.form.requestSubmit();
	});

	document.addEventListener('submit', function (event) {
		var form = event.target;
		var root = form.closest ? form.closest('#' + ROOT_ID) : null;
		if (!root) {
			return;
		}

		event.preventDefault();
		submitInBackground(form.getAttribute('action') || form.action, new FormData(form), root);
	});
})();
