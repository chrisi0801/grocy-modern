// Connection status (Grocy Modern)
//
// Grocy talks to its server with plain XHRs that have no timeout, and most
// views only log a failed request to the console. On a flaky link - a VPN
// re-establishing its tunnel after the phone woke up, a connection that died
// on the way from Wi-Fi to mobile data - a request then hangs for minutes or
// fails silently: the tap "does nothing", and a form that disabled its inputs
// while saving stays disabled. Worse, the browser only opens a handful of
// connections per server, so a few hanging requests block every later one,
// page loads included - the whole app seems frozen.
//
// This makes that visible and bounded:
//   - every API request gets a timeout, so a dead request ends, frees its
//     connection and the view's own error handling (re-enabling the form) runs
//   - a request that failed for lack of a connection says so in a toast
//   - a dot in the top bar shows the state: green (fine), yellow (slow, or
//     waiting for an answer), red (no connection, logged out, server down)
//   - tapping it shows the details and runs a check that also tells a server
//     that is down from a VPN that lets small answers through but not large
//     ones (the classic MTU problem)
//
// Nothing here changes what a request does - only how long it may take and
// what the user gets to see about it.

var GrocyConnection = {
	// An API request without an answer after this long is given up
	RequestTimeout: 20000,
	// The heartbeat is a tiny request - if it takes this long, nothing works
	HeartbeatTimeout: 8000,
	// How often the heartbeat runs while everything is fine / while it is not
	HeartbeatInterval: 30000,
	RetryInterval: 5000,
	// A request still waiting after this long turns the dot yellow
	SlowAfter: 2500,
	// ...and so does a heartbeat that took longer than this
	SlowLatency: 1500,
	// The large answer the check downloads - a PNG, so nothing compresses it
	LargeTestFile: "/img/icon-512.png",
	LargeTestTimeout: 12000,
	// Failure toasts are not repeated more often than this
	ToastInterval: 10000,

	Pending: [],
	LastSuccess: Date.now(),
	LastLatency: null,
	LastCheck: null,
	Failure: null,
	Log: [],
	LastToast: 0,
	Unloading: false,
	CheckRunning: false,
	HeartbeatTimer: null,
	Diagnosis: null
};

GrocyConnection.IsEmbedded = function ()
{
	return document.body.classList.contains("embedded") && window.parent !== window;
};

// The page that shows the dot - for a dialog that is the page behind it
GrocyConnection.Host = function ()
{
	if (GrocyConnection.IsEmbedded())
	{
		try
		{
			if (window.parent.GrocyConnection)
			{
				return window.parent.GrocyConnection;
			}
		}
		catch (e)
		{
		}
	}

	return GrocyConnection;
};

GrocyConnection.ApiPath = function ()
{
	return new URL(U("/api/"), window.location.href).pathname;
};

// "stock/products/12/consume" for a request to our own API, otherwise null
GrocyConnection.ApiFunction = function (url)
{
	try
	{
		var target = new URL(url, window.location.href);
		var apiPath = GrocyConnection.ApiPath();

		if (target.origin !== window.location.origin || target.pathname.indexOf(apiPath) !== 0)
		{
			return null;
		}

		return target.pathname.substring(apiPath.length);
	}
	catch (e)
	{
		return null;
	}
};

// Background polling is nobody's input - it updates the dot, but a failure
// of it is not worth a toast
GrocyConnection.IsBackground = function (apiFunction)
{
	return apiFunction === "system/db-changed-time";
};

// --- bookkeeping of the requests ---------------------------------------

GrocyConnection.Classify = function (record)
{
	if (record.aborted)
	{
		return null;
	}

	if (record.timedOut)
	{
		return "timeout";
	}

	var status = record.xhr.status;

	if (status === 0)
	{
		return "network";
	}

	if (status === 401)
	{
		return "auth";
	}

	// What a reverse proxy answers when Grocy behind it is not reachable
	if (status === 502 || status === 503 || status === 504)
	{
		return "server";
	}

	return "ok";
};

GrocyConnection.Started = function (record)
{
	this.Pending.push(record);
	this.Render();
};

GrocyConnection.Finished = function (record, outcome)
{
	var index = this.Pending.indexOf(record);

	if (index !== -1)
	{
		this.Pending.splice(index, 1);
	}

	if (outcome === "ok")
	{
		// Any answer at all proves the way to the server works - a 400 for a
		// validation error included
		this.LastSuccess = Date.now();
		this.Failure = null;
	}
	else if (outcome !== null && !this.Unloading)
	{
		this.Failure = {
			kind: outcome,
			at: Date.now(),
			method: record.method,
			path: record.path,
			status: record.status,
			waited: Date.now() - record.start
		};

		this.Log.unshift(this.Failure);
		this.Log = this.Log.slice(0, 8);

		if (!record.quiet)
		{
			this.Toast(this.Failure);
		}

		// Find out quickly when it works again
		this.ScheduleHeartbeat(this.RetryInterval);
	}

	this.Render();
};

GrocyConnection.Track = function (xhr, method, url)
{
	var apiFunction = GrocyConnection.ApiFunction(url);

	if (apiFunction === null)
	{
		return;
	}

	var record = {
		xhr: xhr,
		method: method,
		path: apiFunction.split("?")[0],
		start: Date.now(),
		quiet: xhr._grocyConnectionQuiet === true || GrocyConnection.IsBackground(apiFunction.split("?")[0]),
		timeout: xhr.timeout,
		aborted: false,
		timedOut: false,
		status: null
	};

	var host = GrocyConnection.Host();

	xhr.addEventListener("timeout", function ()
	{
		record.timedOut = true;
	});

	xhr.addEventListener("abort", function ()
	{
		record.aborted = true;
	});

	xhr.addEventListener("loadend", function ()
	{
		record.status = xhr.status;
		host.Finished(record, GrocyConnection.Classify(record));
	});

	host.Started(record);
};

// Every XHR goes through here - Grocy.Api, jQuery and anything else. Only
// requests to our own API are touched.
(function ()
{
	var open = XMLHttpRequest.prototype.open;
	var send = XMLHttpRequest.prototype.send;

	XMLHttpRequest.prototype.open = function (method, url, async)
	{
		this._grocyConnection = {
			method: String(method).toUpperCase(),
			url: String(url),
			// A synchronous request cannot have a timeout (and blocks anyway)
			async: arguments.length < 3 || async !== false
		};

		return open.apply(this, arguments);
	};

	XMLHttpRequest.prototype.send = function (body)
	{
		var request = this._grocyConnection;

		if (request && request.async && GrocyConnection.ApiFunction(request.url) !== null)
		{
			// Uploads (product pictures and the like) may take their time
			var isUpload = (typeof Blob !== "undefined" && body instanceof Blob)
				|| (typeof ArrayBuffer !== "undefined" && body instanceof ArrayBuffer)
				|| (typeof FormData !== "undefined" && body instanceof FormData);

			if (this.timeout === 0 && !isUpload)
			{
				this.timeout = GrocyConnection.RequestTimeout;
			}

			try
			{
				GrocyConnection.Track(this, request.method, request.url);
			}
			catch (e)
			{
				// Bookkeeping must never stop a request
				console.error(e);
			}
		}

		return send.apply(this, arguments);
	};
})();

// --- telling the user ----------------------------------------------------

GrocyConnection.Seconds = function (ms)
{
	return Math.max(1, Math.round(ms / 1000)).toLocaleString();
};

GrocyConnection.Toast = function (failure)
{
	if (Date.now() - this.LastToast < this.ToastInterval || typeof toastr === "undefined")
	{
		return;
	}

	this.LastToast = Date.now();

	var message;

	switch (failure.kind)
	{
		case "timeout":
			message = __t("The server did not answer within %s seconds. Whether your last input was saved is unclear - please check before repeating it.", GrocyConnection.Seconds(failure.waited));
			break;
		case "auth":
			message = __t("You are no longer logged in - please log in again.");
			break;
		case "server":
			message = __t("The server is not reachable right now (HTTP %s).", failure.status);
			break;
		default:
			message = __t("No connection to the server - your last input was probably not saved.");
	}

	toastr.error(message + '<br><a href="#" class="connection-details-link">' + __t("Check connection") + "</a>");
};

// Grocy's own error toast for a request that never got an answer only says
// "Error while saving" with empty details - the connection toast says more,
// so that one is left out
GrocyConnection.WrapGenericError = function ()
{
	if (!Grocy.FrontendHelpers || !Grocy.FrontendHelpers.ShowGenericError)
	{
		return;
	}

	var original = Grocy.FrontendHelpers.ShowGenericError;

	Grocy.FrontendHelpers.ShowGenericError = function (message, exception)
	{
		var args = arguments;
		var self = this;

		// The connection bookkeeping runs right after the view's handler -
		// wait for it before deciding
		setTimeout(function ()
		{
			var failure = GrocyConnection.Host().Failure;
			var withoutAnswer = exception === undefined || exception === null || exception === "";

			if (withoutAnswer && failure !== null && Date.now() - failure.at < 2000)
			{
				console.error(exception);
				return;
			}

			original.apply(self, args);
		}, 0);
	};
};

// --- the dot -----------------------------------------------------------

GrocyConnection.LongestPending = function ()
{
	var now = Date.now();
	var longest = 0;

	// A request of a dialog that was closed meanwhile never reports back -
	// it must not keep the dot yellow forever
	this.Pending = this.Pending.filter(function (record)
	{
		var limit = record.timeout > 0 ? record.timeout + 10000 : 600000;

		try
		{
			if (record.xhr.readyState === XMLHttpRequest.DONE)
			{
				return false;
			}
		}
		catch (e)
		{
			return false;
		}

		return now - record.start < limit;
	});

	this.Pending.forEach(function (record)
	{
		longest = Math.max(longest, now - record.start);
	});

	return longest;
};

// ok | slow | waiting | offline | auth | server
GrocyConnection.Status = function ()
{
	if (navigator.onLine === false)
	{
		return "offline";
	}

	if (this.Failure !== null)
	{
		return this.Failure.kind === "auth" ? "auth" : (this.Failure.kind === "server" ? "server" : "offline");
	}

	if (this.LongestPending() >= this.SlowAfter)
	{
		return "waiting";
	}

	if (this.LastLatency !== null && this.LastLatency >= this.SlowLatency)
	{
		return "slow";
	}

	return "ok";
};

GrocyConnection.Color = function (status)
{
	if (status === "ok")
	{
		return "green";
	}

	if (status === "slow" || status === "waiting")
	{
		return "yellow";
	}

	return "red";
};

GrocyConnection.StatusText = function (status)
{
	switch (status)
	{
		case "ok":
			return __t("Connected");
		case "slow":
			return __t("Slow connection");
		case "waiting":
			return __t("Waiting for the server");
		case "auth":
			return __t("Logged out");
		case "server":
			return __t("Server not reachable");
		default:
			return __t("No connection");
	}
};

GrocyConnection.Render = function ()
{
	var indicator = document.getElementById("connection-indicator");

	if (!indicator)
	{
		return;
	}

	var status = this.Status();
	var color = this.Color(status);
	var text = this.StatusText(status);

	if (indicator.getAttribute("data-status") !== status)
	{
		indicator.setAttribute("data-status", status);
		indicator.setAttribute("data-color", color);
		indicator.setAttribute("title", text);
		indicator.setAttribute("aria-label", __t("Connection") + ": " + text);
		indicator.querySelector(".connection-label").textContent = text;
	}

	if (this.DialogOpen)
	{
		this.RenderDetails();
	}
};

// --- the heartbeat ---------------------------------------------------------

GrocyConnection.ScheduleHeartbeat = function (delay)
{
	if (GrocyConnection.IsEmbedded() || !document.getElementById("connection-indicator"))
	{
		return;
	}

	clearTimeout(this.HeartbeatTimer);
	this.HeartbeatTimer = setTimeout(function ()
	{
		GrocyConnection.Heartbeat();
	}, delay);
};

// One small authenticated request. Calls back with { ok, kind, ms, status }.
GrocyConnection.Ping = function (callback, url, timeout, quietAuth)
{
	var xhr = new XMLHttpRequest();
	var start = performance.now();
	var timedOut = false;

	xhr.open("GET", url || U("/api/system/db-changed-time"), true);
	xhr.timeout = timeout || this.HeartbeatTimeout;
	xhr._grocyConnectionQuiet = true;
	xhr.ontimeout = function ()
	{
		timedOut = true;
	};
	xhr.onloadend = function ()
	{
		var ms = Math.round(performance.now() - start);
		var kind = timedOut ? "timeout" : (xhr.status === 0 ? "network" : (xhr.status === 401 ? "auth" : (xhr.status >= 500 ? "server" : "ok")));
		callback({ ok: kind === "ok", kind: kind, ms: ms, status: xhr.status, bytes: xhr.response ? (xhr.response.byteLength || xhr.response.length || 0) : 0 });
	};

	if (url)
	{
		xhr.responseType = "arraybuffer";
	}

	xhr.send();
};

GrocyConnection.Heartbeat = function ()
{
	if (document.visibilityState === "hidden")
	{
		// Picked up again on visibilitychange
		return;
	}

	this.Ping(function (result)
	{
		GrocyConnection.LastCheck = Date.now();

		if (result.ok)
		{
			GrocyConnection.LastLatency = result.ms;
		}

		GrocyConnection.Render();
		GrocyConnection.ScheduleHeartbeat(result.ok && GrocyConnection.Failure === null ? GrocyConnection.HeartbeatInterval : GrocyConnection.RetryInterval);
	});
};

// --- the details -------------------------------------------------------------

GrocyConnection.Ago = function (timestamp)
{
	if (!timestamp)
	{
		return "-";
	}

	return moment(timestamp).fromNow();
};

GrocyConnection.Explanation = function (status)
{
	switch (status)
	{
		case "ok":
			return __t("Inputs are being processed normally.");
		case "slow":
			return __t("The server answers, but slowly. Inputs are processed, it just takes a moment.");
		case "waiting":
			return __t("A request has been waiting for %s seconds. If nothing happens, it is cancelled after %s seconds and you get a message.", GrocyConnection.Seconds(this.LongestPending()), GrocyConnection.Seconds(this.RequestTimeout));
		case "auth":
			return __t("Your login has expired - inputs are rejected until you log in again.");
		case "server":
			return __t("The web server answers, but Grocy behind it does not. Is the container running?");
		default:
			return navigator.onLine === false
				? __t("This device has no network right now.")
				: __t("Inputs cannot be saved right now. Is the VPN connected and the server switched on?");
	}
};

GrocyConnection.Escape = function (text)
{
	return $("<div></div>").text(text).html();
};

GrocyConnection.FailureText = function (failure)
{
	switch (failure.kind)
	{
		case "timeout":
			return __t("no answer after %s s", GrocyConnection.Seconds(failure.waited));
		case "auth":
			return __t("not logged in");
		case "server":
			return "HTTP " + failure.status;
		default:
			return __t("connection failed");
	}
};

GrocyConnection.RenderDetails = function ()
{
	var container = $("#connection-details");

	if (container.length === 0)
	{
		return;
	}

	var status = this.Status();
	var html = '<div class="connection-details-head" data-color="' + this.Color(status) + '">'
		+ '<span class="connection-dot"></span><strong>' + this.Escape(this.StatusText(status)) + "</strong></div>"
		+ '<p class="connection-details-text">' + this.Escape(this.Explanation(status)) + "</p>";

	if (status === "auth")
	{
		html += '<p><a class="btn btn-primary btn-sm" href="' + U("/login") + '">' + __t("Log in again") + "</a></p>";
	}

	html += '<table class="connection-facts">'
		+ "<tr><td>" + __t("Response time") + "</td><td>" + (this.LastLatency !== null ? this.LastLatency.toLocaleString() + " ms" : "-") + "</td></tr>"
		+ "<tr><td>" + __t("Last successful contact") + "</td><td>" + this.Escape(this.Ago(this.LastSuccess)) + "</td></tr>"
		+ "<tr><td>" + __t("Waiting requests") + "</td><td>" + this.Pending.length + "</td></tr>"
		+ "<tr><td>" + __t("Device network") + "</td><td>" + (navigator.onLine === false ? __t("offline") : __t("online")) + "</td></tr>"
		+ "</table>";

	if (this.Diagnosis !== null)
	{
		html += '<div class="connection-section-title">' + __t("Connection test") + "</div>" + this.Diagnosis;
	}

	if (this.Log.length > 0)
	{
		html += '<div class="connection-section-title">' + __t("Recent problems") + '</div><ul class="connection-log">';
		this.Log.forEach(function (failure)
		{
			html += "<li><span>" + GrocyConnection.Escape(moment(failure.at).format("HH:mm:ss")) + "</span> "
				+ "<code>" + GrocyConnection.Escape(failure.method + " " + failure.path) + "</code> "
				+ GrocyConnection.Escape(GrocyConnection.FailureText(failure)) + "</li>";
		});
		html += "</ul>";
	}

	container.html(html);
};

// Small request three times, then one large answer. Small fine but large
// stuck is the signature of a too large MTU on the VPN tunnel.
GrocyConnection.RunTest = function (done)
{
	var self = this;
	var lines = [];
	var times = [];

	var line = function (ok, text)
	{
		return '<li class="' + (ok ? "is-ok" : "is-failed") + '"><i class="fa-solid ' + (ok ? "fa-check" : "fa-xmark") + '"></i> ' + text + "</li>";
	};

	var finish = function (verdict)
	{
		self.Diagnosis = '<ul class="connection-test">' + lines.join("") + "</ul>" + (verdict ? '<p class="connection-verdict">' + verdict + "</p>" : "");
		self.RenderDetails();
		done();
	};

	self.Diagnosis = '<p class="connection-details-text"><i class="fa-solid fa-spinner fa-spin"></i> ' + __t("Testing the connection...") + "</p>";
	self.RenderDetails();

	var small = function (round)
	{
		self.Ping(function (result)
		{
			if (!result.ok)
			{
				if (result.kind === "auth")
				{
					lines.push(line(true, __t("Server reachable")));
					lines.push(line(false, __t("Logged in")));
					finish(__t("Your login has expired - inputs are rejected until you log in again."));
				}
				else
				{
					lines.push(line(false, __t("Server reachable") + " - " + (result.kind === "timeout" ? __t("no answer after %s s", GrocyConnection.Seconds(result.ms)) : (result.kind === "server" ? "HTTP " + result.status : __t("connection failed")))));
					finish(result.kind === "server"
						? __t("The web server answers, but Grocy behind it does not. Is the container running?")
						: __t("The server cannot be reached at all. Is the VPN connected, and is the server switched on? If this happens right after unlocking the phone, wait a few seconds for the VPN to reconnect."));
				}
				return;
			}

			times.push(result.ms);

			if (round < 2)
			{
				small(round + 1);
				return;
			}

			times.sort(function (a, b) { return a - b; });
			self.LastLatency = times[1];
			self.LastSuccess = Date.now();
			self.Failure = null;
			lines.push(line(true, __t("Server reachable") + " - " + times[1].toLocaleString() + " ms"));
			lines.push(line(true, __t("Logged in")));
			large();
		}, null, self.HeartbeatTimeout);
	};

	var large = function ()
	{
		self.Ping(function (result)
		{
			if (!result.ok || result.bytes < 50000)
			{
				lines.push(line(false, __t("Large answer (%s KB)", "100") + " - " + (result.kind === "timeout" ? __t("no answer after %s s", GrocyConnection.Seconds(result.ms)) : __t("connection failed"))));
				finish(__t("Small requests get through, large answers do not. That is the typical sign of a too large MTU on the VPN tunnel - for WireGuard, set the MTU of the tunnel to 1280 (in the app: Edit tunnel, MTU) and try again."));
				return;
			}

			var kbPerSecond = Math.round(result.bytes / 1024 / Math.max(result.ms / 1000, 0.001));
			lines.push(line(true, __t("Large answer (%s KB)", Math.round(result.bytes / 1024)) + " - " + result.ms.toLocaleString() + " ms (" + kbPerSecond.toLocaleString() + " KB/s)"));

			var verdict = times[1] >= self.SlowLatency
				? __t("Everything gets through, but slowly - inputs work, they just take a moment.")
				: __t("Everything works - if inputs hung before, the connection was interrupted for a moment (for example while the VPN reconnected).");
			finish(verdict);
		}, U(self.LargeTestFile) + "?connection-test=" + Date.now(), self.LargeTestTimeout);
	};

	small(0);
};

GrocyConnection.ShowDetails = function ()
{
	if (GrocyConnection.IsEmbedded())
	{
		var host = GrocyConnection.Host();

		if (host !== GrocyConnection)
		{
			host.ShowDetails();
			return;
		}
	}

	if (GrocyConnection.DialogOpen)
	{
		return;
	}

	GrocyConnection.Diagnosis = null;

	var dialog = bootbox.dialog({
		message: '<div id="connection-details"></div>',
		className: "connection-dialog",
		size: "small",
		onEscape: true,
		backdrop: true,
		closeButton: false,
		buttons: {
			test: {
				label: '<i class="fa-solid fa-stethoscope"></i> ' + __t("Test connection"),
				className: "btn-outline-dark connection-test-button",
				callback: function ()
				{
					var button = dialog.find(".connection-test-button").prop("disabled", true);
					GrocyConnection.RunTest(function ()
					{
						button.prop("disabled", false);
						GrocyConnection.Render();
					});
					return false;
				}
			},
			reload: {
				label: __t("Reload page"),
				className: "btn-outline-dark",
				callback: function ()
				{
					window.location.reload();
				}
			},
			ok: {
				label: __t("OK"),
				className: "btn-primary"
			}
		}
	});

	GrocyConnection.DialogOpen = true;
	GrocyConnection.RenderDetails();

	// "waiting since" and "x seconds ago" keep moving while it is open
	var ticker = setInterval(function ()
	{
		GrocyConnection.RenderDetails();
	}, 1000);

	dialog.on("hidden.bs.modal", function ()
	{
		clearInterval(ticker);
		GrocyConnection.DialogOpen = false;
	});
};

// --- wiring ------------------------------------------------------------------

GrocyConnection.WrapGenericError();

$(document).on("click", "#connection-indicator, .connection-details-link", function (e)
{
	e.preventDefault();
	GrocyConnection.ShowDetails();
});

if (!GrocyConnection.IsEmbedded())
{
	// Waiting requests turn the dot yellow while they wait, not only when
	// they are done
	setInterval(function ()
	{
		if (GrocyConnection.Pending.length > 0)
		{
			GrocyConnection.Render();
		}
	}, 1000);

	// The moment that matters most: the phone was unlocked, the app came back
	// to the front - and the VPN may still be reconnecting
	document.addEventListener("visibilitychange", function ()
	{
		if (document.visibilityState === "visible")
		{
			GrocyConnection.Heartbeat();
		}
	});

	window.addEventListener("pageshow", function (e)
	{
		if (e.persisted)
		{
			GrocyConnection.Unloading = false;
			GrocyConnection.Heartbeat();
		}
	});

	window.addEventListener("online", function ()
	{
		GrocyConnection.Render();
		GrocyConnection.Heartbeat();
	});

	window.addEventListener("offline", function ()
	{
		GrocyConnection.Render();
	});

	GrocyConnection.Render();
	GrocyConnection.ScheduleHeartbeat(3000);
}

// Leaving the page cancels its requests - that is not a connection problem
window.addEventListener("beforeunload", function ()
{
	GrocyConnection.Unloading = true;

	// The navigation may be cancelled (or never complete) - stay honest then
	setTimeout(function ()
	{
		GrocyConnection.Unloading = false;
	}, 10000);
});

window.addEventListener("pagehide", function ()
{
	GrocyConnection.Unloading = true;
});
