// Simple Download Monitor frontend scripts

jQuery(document).ready(function($) {
	
	
	$('.pass_sumbit').click(function() {
		
		this_button_id = $(this).next().val();  // Get download cpt id from hidden input field
		password_attempt = $(this).prev().val();  // Get password text
		
		$.post(
			sdm_ajax_script.ajaxurl,
			{
			 action: 'sdm_check_pass',
			 pass_val: password_attempt,
			 button_id: this_button_id
			},
			function(response) {
				
				if(response) {  // ** If response was successful
				
					if(response.success === 'no') {  // If the password match failed
						
						alert('Incorrect Password');
						$('.pass_text').val('');  // Clear password field
					}
					
					if(response.url != '') {  // If the password match was a success
						
						window.location.href = response.url;  // Redirect to download url
						$('.pass_text').val('');  // Clear password field
					}
				} 
			}
		);
	});
	
});