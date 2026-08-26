(function () {
	var ROOT_ID = 'freshrss-autottl-configure';

	function refresh(root) {
		var url = root.getAttribute('data-refresh-url');
		if (!url) {
			return;
		}

		fetch(url, { credentials: 'same-origin' })
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

	// Submits a form (or single button press) via fetch instead of a native
	// submission, so the response never replaces the whole page - critical when
	// this content is loaded into FreshRSS's slider panel, where a real
	// navigation breaks out of the panel entirely. Always re-fetches the
	// current state afterward instead of trusting an optimistic local update,
	// since e.g. a back-off toggle changes server-computed TTLs/labels too.
	function submitInBackground(form, root) {
		fetch(form.getAttribute('action') || form.action, {
			method: 'POST',
			credentials: 'same-origin',
			redirect: 'manual',
			body: new FormData(form),
		}).catch(function () {}).then(function () {
			refresh(root);
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
		fetch(button.getAttribute('formaction'), {
			method: 'POST',
			credentials: 'same-origin',
			redirect: 'manual',
			body: new FormData(button.form),
		}).catch(function () {}).then(function () {
			refresh(root);
		});
	});

	document.addEventListener('submit', function (event) {
		var root = event.target.closest ? event.target.closest('#' + ROOT_ID) : null;
		if (!root) {
			return;
		}

		event.preventDefault();
		submitInBackground(event.target, root);
	});
})();
