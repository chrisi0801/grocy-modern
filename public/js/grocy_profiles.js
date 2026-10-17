// Profiles (Grocy Modern) - switching between the separate households.
//
// The profile of the browser is a cookie; the server reads it on every page
// load (see helpers/Profiles.php). The API requests of a page carry the profile
// the page belongs to as a header instead (grocy_connection.js), so a tab that
// still shows the other household can never change data here.

var GrocyProfiles = {
	CookieName: "grocy_profile"
};

GrocyProfiles.SetCookie = function (profileId)
{
	document.cookie = GrocyProfiles.CookieName + "=" + encodeURIComponent(profileId) + "; path=/; max-age=31536000; samesite=lax";
};

// The same page in the other household - unless the page is about one
// particular thing (a product, a recipe, ...): its id means something else
// over there, so that goes to the start page
GrocyProfiles.TargetUrl = function ()
{
	var basePath = new URL(U("/"), window.location.href).pathname;
	var path = window.location.pathname;

	if (GetUriParam("embedded") !== undefined || /\/(\d+|new)(\/|$)/.test(path) || path.indexOf(basePath) !== 0)
	{
		return U("/");
	}

	return window.location.origin + path;
};

GrocyProfiles.Switch = function (profileId, url)
{
	GrocyProfiles.SetCookie(profileId);
	window.location.href = url || GrocyProfiles.TargetUrl();
};

$(document).on("click", "[data-profile-switch]", function (e)
{
	e.preventDefault();

	var profileId = $(this).attr("data-profile-switch");

	if (profileId === Grocy.ProfileId && $(this).closest(".login-profiles").length === 0)
	{
		return;
	}

	// On the login page: stay there, just for the other profile
	GrocyProfiles.Switch(profileId, $(this).closest(".login-profiles").length > 0 ? U("/login") : undefined);
});

// Logging in to a profile this browser has no login for yet
$(document).on("click", "[data-profile-login]", function (e)
{
	e.preventDefault();
	window.location.href = U("/login?choose-profile");
});
