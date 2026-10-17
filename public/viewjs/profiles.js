function ProfileById(profileId)
{
	return ProfilesPage.Profiles.find(function (profile)
	{
		return profile.id === profileId;
	});
}

function ProfileShowError(xhr)
{
	var response = null;

	try
	{
		response = JSON.parse(xhr.response);
	}
	catch (e)
	{
	}

	if (response && response.error_message)
	{
		toastr.error(response.error_message);
	}
	else
	{
		Grocy.FrontendHelpers.ShowGenericError("Error while saving, probably this item already exists", xhr.response);
	}
}

// One dialog for both "new" and "edit" - the form lives in a <template>
function ProfileDialog(profile)
{
	var isNew = profile === null;

	var dialog = bootbox.dialog({
		title: isNew ? __t("New profile") : __t("Edit profile"),
		message: $("#profile-form-template").html(),
		onEscape: true,
		backdrop: true,
		buttons: {
			cancel: {
				label: __t("Cancel"),
				className: "btn-secondary"
			},
			save: {
				label: isNew ? __t("Create profile") : __t("Save"),
				className: "btn-success",
				callback: function ()
				{
					var form = dialog.find(".profile-form")[0];
					var name = dialog.find("#profile-name").val().trim();
					dialog.find("#profile-name")[0].setCustomValidity(name === "" ? __t("A name is required") : "");
					form.classList.add("was-validated");

					if (!form.checkValidity())
					{
						return false;
					}

					var data = {
						"name": name,
						"color": dialog.find("input[name='profile-color']:checked").val()
					};

					var buttons = dialog.find(".modal-footer .btn").prop("disabled", true);

					var failed = function (xhr)
					{
						buttons.prop("disabled", false);
						ProfileShowError(xhr);
					};

					if (isNew)
					{
						data.copy_master_data = dialog.find("#profile-copy-master-data").is(":checked");

						// Setting up a database takes a moment
						dialog.find(".modal-footer .btn-success").html('<i class="fa-solid fa-spinner fa-spin"></i> ' + __t("Creating profile..."));

						Grocy.Api.Post("profiles", data,
							function (created)
							{
								dialog.modal("hide");
								ProfileCreated(created);
							},
							failed
						);
					}
					else
					{
						Grocy.Api.Put("profiles/" + profile.id, data,
							function ()
							{
								window.location.reload();
							},
							failed
						);
					}

					return false;
				}
			}
		}
	});

	var color = isNew ? ProfilesPage.Colors[ProfilesPage.Profiles.length % ProfilesPage.Colors.length] : profile.color;
	dialog.find("input[name='profile-color'][value='" + color + "']").prop("checked", true);
	dialog.find(".profile-create-only").toggleClass("d-none", !isNew);

	if (!isNew)
	{
		dialog.find("#profile-name").val(profile.name || ProfilesPage.MainProfileName);
	}

	dialog.on("shown.bs.modal", function ()
	{
		dialog.find("#profile-name").trigger("focus");
	});

	dialog.find("#profile-name").on("keydown", function (e)
	{
		if (e.keyCode === 13)
		{
			e.preventDefault();
			dialog.find(".modal-footer .btn-success").trigger("click");
		}
	});
}

function ProfileCreated(profile)
{
	bootbox.dialog({
		title: __t("Profile created"),
		message: "<p>" + __t('The profile "%s" is ready, and you are already logged in there with your user name and password.', $("<span></span>").text(profile.name).html()) + "</p>",
		closeButton: false,
		buttons: {
			stay: {
				label: __t("Stay here"),
				className: "btn-secondary",
				callback: function ()
				{
					window.location.reload();
				}
			},
			go: {
				label: __t("Switch to it"),
				className: "btn-primary",
				callback: function ()
				{
					GrocyProfiles.Switch(profile.id, U("/"));
				}
			}
		}
	});
}

$("#profile-new-button").on("click", function ()
{
	ProfileDialog(null);
});

$(document).on("click", ".profile-edit-button", function ()
{
	ProfileDialog(ProfileById($(this).attr("data-profile-id")));
});

// Deleting a household is not something to do by accident: the name has to
// be typed. The data is kept in data/profiles-deleted/ anyway.
$(document).on("click", ".profile-delete-button", function ()
{
	var profile = ProfileById($(this).attr("data-profile-id"));

	bootbox.prompt({
		title: __t('Delete the profile "%s"?', profile.name),
		message: "<p>" + __t("Its stock, chores, tasks, calendar, budget and users are no longer reachable. The data itself is moved to data/profiles-deleted and can be restored from there by hand.") + "</p><p>" + __t("Type the name of the profile to confirm:") + "</p>",
		closeButton: false,
		buttons: {
			cancel: { label: __t("Cancel"), className: "btn-secondary" },
			confirm: { label: __t("Delete"), className: "btn-danger" }
		},
		callback: function (typed)
		{
			if (typed === null)
			{
				return;
			}

			if (typed.trim() !== profile.name)
			{
				toastr.error(__t("The name does not match - nothing was deleted."));
				return;
			}

			Grocy.Api.Delete("profiles/" + profile.id, {},
				function ()
				{
					window.location.reload();
				},
				ProfileShowError
			);
		}
	});
});
