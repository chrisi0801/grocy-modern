function BarcodeLookupError(xhr)
{
	var message = __t("Error while saving, probably this item already exists");

	try
	{
		var response = JSON.parse(xhr.response);
		message = response.error_message || message;
	}
	catch (e)
	{
	}

	toastr.error(message);
}

$("#save-barcodelookup-settings-button").on("click", function (e)
{
	e.preventDefault();

	var data = {
		"ai_enabled": $("#ai_enabled").is(":checked"),
		"api_key": $("#api_key").val().trim(),
		"monthly_limit": $("#monthly_limit").val(),
		"ai_model": $("#ai_model").val(),
		"max_searches": $("#max_searches").val(),
		"remove_key": $("#remove_key").is(":checked")
	};

	Grocy.FrontendHelpers.BeginUiBusy("barcodelookup-settings-form");

	Grocy.Api.Put("barcodelookup/settings", data,
		function ()
		{
			window.location.reload();
		},
		function (xhr)
		{
			Grocy.FrontendHelpers.EndUiBusy("barcodelookup-settings-form");
			BarcodeLookupError(xhr);
		}
	);
});

// Free: it only asks the API which model is there
$("#test-barcodelookup-key-button").on("click", function ()
{
	var button = $(this).prop("disabled", true);

	Grocy.Api.Post("barcodelookup/settings/test", {},
		function (result)
		{
			button.prop("disabled", false);
			(result.ok ? toastr.success : toastr.error)(result.message);
		},
		function (xhr)
		{
			button.prop("disabled", false);
			BarcodeLookupError(xhr);
		}
	);
});
