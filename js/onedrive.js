$(document).ready(function() {
	var backendId = 'files_external_onedrive';
	var backendUrl = OC.generateUrl('apps/' + backendId + '/oauth');
	$('#files_external').on('oauth_step1', '.files_external_onedrive .configuration', function (event, data) {
		if (data['backend_id'] !== backendId) {
			return false;	// means the trigger is not for this storage adapter
		}
		console.log(data);
		OCA.External.Settings.OAuth2.getAuthUrl(backendUrl, data);
	})

	$('#files_external').on('oauth_step2', '.files_external_onedrive .configuration', function (event, data) {
		if (data['backend_id'] !== backendId || data['code'] === undefined) {
			return false;		// means the trigger is not for this OAuth2 grant
		}
		console.log(data);
		OCA.External.Settings.OAuth2.verifyCode(backendUrl, data)
		.fail(function (message) {
			OC.dialogs.alert(message,
				t(backendId, 'Error verifying OAuth2 Code for ' + backendId)
			);
		})
	})

	function generateUrl($tr) {
		return 'https://apps.dev.microsoft.com/';
	}

	OCA.External.Settings.mountConfig.whenSelectBackend(function($tr, backend, onCompletion) {
		if (backend === backendId) {
			var backendEl = $tr.find('.backend');
			var el = $(document.createElement('a'))
				.attr('href', generateUrl($tr))
				.attr('target', '_blank')
				.attr('title', t('files_external', 'OneDrive App Configuration'))
				.addClass('icon-settings svg')
			;
			el.on('click', function(event) {
				var a = $(event.target);
				a.attr('href', generateUrl($(this).closest('tr')));
			});
			el.tooltip({placement: 'top'});
			backendEl.append(el);
		}
	});

});
