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
			}).catch(function () {}).then(function () {
				return refresh(root);
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
