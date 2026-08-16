(function () {
	'use strict';

	var config = window.GameCollector;
	var app = document.getElementById('game-collector-app');
	if (!config || !app) return;

	var state = { library: [], viewedUser: config.initialUser, isCurrent: config.initialUser === config.currentUser };
	var statuses = config.statuses;
	var statusFilter = document.getElementById('gc-library-status');

	Object.keys(statuses).forEach(function (key) {
		statusFilter.appendChild(option(key, statuses[key]));
	});

	app.querySelectorAll('[data-gc-view]').forEach(function (button) {
		button.addEventListener('click', function () { showView(button.dataset.gcView); });
	});

	statusFilter.addEventListener('change', renderLibrary);
	document.getElementById('gc-search-form').addEventListener('submit', searchGames);
	document.getElementById('gc-member-search').addEventListener('submit', loadMembers);
	document.getElementById('gc-refresh-feed').addEventListener('click', loadFeed);

	function api(path, options) {
		options = options || {};
		options.headers = Object.assign({ 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' }, options.headers || {});
		return fetch(config.root.replace(/\/$/, '') + path, options).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (data) {
				if (!response.ok) throw new Error(data.message || 'Something went wrong.');
				return data;
			});
		});
	}

	function showView(view) {
		app.querySelectorAll('[data-gc-view]').forEach(function (button) {
			button.classList.toggle('is-active', button.dataset.gcView === view);
		});
		app.querySelectorAll('[data-gc-panel]').forEach(function (panel) {
			var active = panel.dataset.gcPanel === view;
			panel.classList.toggle('is-active', active);
			panel.hidden = !active;
		});
		if (view === 'feed') loadFeed();
		if (view === 'members') loadMembers();
	}

	function notice(message, isError) {
		var box = app.querySelector('.gc-notice');
		box.textContent = message;
		box.classList.toggle('is-error', Boolean(isError));
		box.hidden = !message;
		if (message) window.setTimeout(function () { box.hidden = true; }, 5000);
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined && text !== null) node.textContent = text;
		return node;
	}

	function option(value, label) {
		var node = el('option', '', label);
		node.value = value;
		return node;
	}

	function image(url, alt, className) {
		if (!url) return el('div', (className || '') + ' gc-image-placeholder', 'No cover');
		var node = document.createElement('img');
		node.className = className || '';
		node.src = url;
		node.alt = alt || '';
		node.loading = 'lazy';
		return node;
	}

	function empty(target, message) {
		target.replaceChildren(el('div', 'gc-empty', message));
	}

	function loadLibrary(userId) {
		var target = document.getElementById('gc-library');
		target.replaceChildren(el('div', 'gc-loading', 'Loading library…'));
		api('/library/' + userId).then(function (data) {
			state.library = data.items;
			state.viewedUser = data.user.id;
			state.isCurrent = data.is_current;
			renderProfile(data);
			renderLibrary();
		}).catch(function (error) {
			empty(target, error.message);
		});
	}

	function renderProfile(data) {
		var target = document.getElementById('gc-profile');
		var profile = el('div', 'gc-profile');
		profile.appendChild(image(data.user.avatar_url, '', 'gc-profile__avatar'));
		var copy = el('div', 'gc-profile__copy');
		copy.appendChild(el('span', 'gc-eyebrow', data.is_current ? 'Your collection' : 'Collector library'));
		copy.appendChild(el('h2', '', data.user.display_name));
		copy.appendChild(el('p', '', state.library.length + (state.library.length === 1 ? ' game' : ' games')));
		profile.appendChild(copy);
		if (!data.is_current) {
			var follow = el('button', 'gc-button gc-button--small', data.following ? 'Following' : 'Follow');
			follow.classList.toggle('is-following', data.following);
			follow.addEventListener('click', function () {
				var currently = follow.classList.contains('is-following');
				follow.disabled = true;
				api('/follows/' + data.user.id, { method: currently ? 'DELETE' : 'POST' }).then(function (result) {
					follow.classList.toggle('is-following', result.following);
					follow.textContent = result.following ? 'Following' : 'Follow';
				}).catch(function (error) { notice(error.message, true); }).finally(function () { follow.disabled = false; });
			});
			profile.appendChild(follow);
		}
		target.replaceChildren(profile);
	}

	function renderLibrary() {
		var target = document.getElementById('gc-library');
		var selected = statusFilter.value;
		var items = state.library.filter(function (item) { return !selected || item.status === selected; });
		if (!items.length) {
			empty(target, selected ? 'No games have this status.' : 'This library is waiting for its first game.');
			return;
		}
		target.replaceChildren();
		items.forEach(function (item) { target.appendChild(libraryCard(item)); });
	}

	function libraryCard(item) {
		var card = el('article', 'gc-card');
		card.appendChild(image(item.cover_url, item.name + ' cover', 'gc-card__cover'));
		var body = el('div', 'gc-card__body');
		body.appendChild(el('span', 'gc-status gc-status--' + item.status, statuses[item.status] || item.status));
		body.appendChild(el('h3', '', item.name));
		var details = [];
		if (item.release_date) details.push(item.release_date.slice(0, 4));
		if (item.platforms && item.platforms.length) details.push(item.platforms.slice(0, 2).join(', '));
		body.appendChild(el('p', 'gc-meta', details.join(' · ')));
		if (state.isCurrent) {
			var actions = el('div', 'gc-card__actions');
			var select = document.createElement('select');
			select.setAttribute('aria-label', 'Status for ' + item.name);
			Object.keys(statuses).forEach(function (key) {
				var choice = option(key, statuses[key]);
				choice.selected = key === item.status;
				select.appendChild(choice);
			});
			select.addEventListener('change', function () { updateStatus(item, select.value, select); });
			var remove = el('button', 'gc-text-button gc-text-button--danger', 'Remove');
			remove.addEventListener('click', function () { removeGame(item, remove); });
			actions.append(select, remove);
			body.appendChild(actions);
		}
		card.appendChild(body);
		return card;
	}

	function updateStatus(item, newStatus, select) {
		var oldStatus = item.status;
		select.disabled = true;
		api('/library/' + item.igdb_id, { method: 'PATCH', body: JSON.stringify({ status: newStatus }) }).then(function () {
			item.status = newStatus;
			notice('Status updated.');
			renderLibrary();
		}).catch(function (error) {
			select.value = oldStatus;
			notice(error.message, true);
		}).finally(function () { select.disabled = false; });
	}

	function removeGame(item, button) {
		if (!window.confirm('Remove ' + item.name + ' from your library?')) return;
		button.disabled = true;
		api('/library/' + item.igdb_id, { method: 'DELETE' }).then(function () {
			state.library = state.library.filter(function (game) { return game.igdb_id !== item.igdb_id; });
			notice('Game removed.');
			renderLibrary();
		}).catch(function (error) {
			notice(error.message, true);
			button.disabled = false;
		});
	}

	function searchGames(event) {
		event.preventDefault();
		var query = document.getElementById('gc-game-query').value.trim();
		var target = document.getElementById('gc-search-results');
		if (query.length < 2) return;
		target.replaceChildren(el('div', 'gc-loading', 'Searching IGDB…'));
		api('/games/search?q=' + encodeURIComponent(query)).then(function (games) {
			if (!games.length) return empty(target, 'No matching games found.');
			target.replaceChildren();
			games.forEach(function (game) { target.appendChild(searchCard(game)); });
		}).catch(function (error) { empty(target, error.message); });
	}

	function searchCard(game) {
		var card = el('article', 'gc-card gc-card--search');
		card.appendChild(image(game.cover_url, game.name + ' cover', 'gc-card__cover'));
		var body = el('div', 'gc-card__body');
		body.appendChild(el('h3', '', game.name));
		var details = [];
		if (game.release_date) details.push(game.release_date.slice(0, 4));
		if (game.platforms && game.platforms.length) details.push(game.platforms.slice(0, 2).join(', '));
		body.appendChild(el('p', 'gc-meta', details.join(' · ')));
		var summary = game.summary || 'No description available.';
		body.appendChild(el('p', 'gc-summary', summary.length > 180 ? summary.slice(0, 177) + '…' : summary));
		var actions = el('div', 'gc-card__actions');
		var select = document.createElement('select');
		select.setAttribute('aria-label', 'Status for ' + game.name);
		Object.keys(statuses).forEach(function (key) { select.appendChild(option(key, statuses[key])); });
		select.value = 'backlog';
		var add = el('button', 'gc-button gc-button--small', 'Add');
		add.addEventListener('click', function () {
			add.disabled = true;
			add.textContent = 'Adding…';
			api('/library', { method: 'POST', body: JSON.stringify({ igdb_id: game.igdb_id, status: select.value }) }).then(function () {
				add.textContent = 'Added';
				add.classList.add('is-added');
				notice(game.name + ' added to your library.');
				loadLibrary(config.currentUser);
			}).catch(function (error) {
				add.disabled = false;
				add.textContent = 'Add';
				notice(error.message, true);
			});
		});
		actions.append(select, add);
		body.appendChild(actions);
		card.appendChild(body);
		return card;
	}

	function loadFeed() {
		var target = document.getElementById('gc-feed');
		target.replaceChildren(el('div', 'gc-loading', 'Loading activity…'));
		api('/feed').then(function (items) {
			if (!items.length) return empty(target, 'Follow a collector or add a game to start your feed.');
			target.replaceChildren();
			items.forEach(function (item) {
				var row = el('article', 'gc-activity');
				row.appendChild(image(item.avatar_url, '', 'gc-activity__avatar'));
				var copy = el('div', 'gc-activity__copy');
				var sentence = item.display_name + ' ' + activityText(item);
				copy.appendChild(el('p', '', sentence));
				copy.appendChild(el('time', '', formatDate(item.created_at)));
				row.appendChild(copy);
				if (item.cover_url) row.appendChild(image(item.cover_url, '', 'gc-activity__cover'));
				target.appendChild(row);
			});
		}).catch(function (error) { empty(target, error.message); });
	}

	function activityText(item) {
		if (item.verb === 'added') return 'added ' + item.game_name + ' to ' + (statuses[item.meta.status] || item.meta.status) + '.';
		if (item.verb === 'status_changed') return 'moved ' + item.game_name + ' to ' + (statuses[item.meta.to] || item.meta.to) + '.';
		if (item.verb === 'removed') return 'removed ' + item.game_name + ' from their library.';
		if (item.verb === 'followed') return 'started following ' + item.target_name + '.';
		return 'updated their collection.';
	}

	function formatDate(value) {
		var date = new Date(value);
		return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
	}

	function loadMembers(event) {
		if (event) event.preventDefault();
		var target = document.getElementById('gc-members');
		var query = document.getElementById('gc-member-query').value.trim();
		target.replaceChildren(el('div', 'gc-loading', 'Loading collectors…'));
		api('/members?q=' + encodeURIComponent(query)).then(function (members) {
			if (!members.length) return empty(target, 'No collectors found.');
			target.replaceChildren();
			members.forEach(function (member) { target.appendChild(memberCard(member)); });
		}).catch(function (error) { empty(target, error.message); });
	}

	function memberCard(member) {
		var row = el('article', 'gc-member');
		row.appendChild(image(member.avatar_url, '', 'gc-member__avatar'));
		var copy = el('div', 'gc-member__copy');
		copy.appendChild(el('h3', '', member.display_name));
		copy.appendChild(el('p', '', member.game_count + (member.game_count === 1 ? ' game' : ' games')));
		row.appendChild(copy);
		var library = el('a', 'gc-text-button', 'View library');
		library.href = config.libraryUrl + (config.libraryUrl.indexOf('?') === -1 ? '?' : '&') + 'user=' + member.id;
		row.appendChild(library);
		var follow = el('button', 'gc-button gc-button--small', member.following ? 'Following' : 'Follow');
		follow.classList.toggle('is-following', member.following);
		follow.addEventListener('click', function () {
			follow.disabled = true;
			api('/follows/' + member.id, { method: member.following ? 'DELETE' : 'POST' }).then(function (result) {
				member.following = result.following;
				follow.textContent = member.following ? 'Following' : 'Follow';
				follow.classList.toggle('is-following', member.following);
			}).catch(function (error) { notice(error.message, true); }).finally(function () { follow.disabled = false; });
		});
		row.appendChild(follow);
		return row;
	}

	loadLibrary(state.viewedUser);
}());
