jQuery(document).ready(function($){
	
	// Run media uploader for file upload
	$('#upload_image_button').click(function() {

        tb_show( '', 'media-upload.php?type=image&amp;TB_iframe=true' );
        
		window.send_to_editor = function(html) {
	
			imgurl = $(html).attr('href');
			$('#sdm_upload').val(imgurl);
			tb_remove();
		}
    });
	
	// Run media uploader for thumbnail upload
	$('#upload_thumbnail_button').click(function() {

        tb_show( '', 'media-upload.php?type=image&amp;TB_iframe=true' );
		
        window.send_to_editor = function(html) {

			imgurl = $(html).attr('href');
			$('#sdm_thumbnail_image').remove();
			$('#sdm_get_thumb').append('<img id="sdm_thumbnail_image" src="'+imgurl+'" />');
			
			$('#sdm_upload_thumbnail').val(imgurl);
			tb_remove();
		}
    });
	
	// Remove thumbnail image from CPT
	$('#remove_thumbnail_button').click(function() {
		$.post(
			sdm_admin_ajax_url.sdm_admin_ajax_url,
			{
			 action: 'sdm_remove_thumbnail_image',
			 post_id_del: sdm_del_thumb_postid
			},
			function(response) {
				if(response) {  // ** If response was successful
					$('#sdm_thumbnail_image').remove();
					$('#sdm_upload_thumbnail').val('');
					alert('Image Successfully Removed');
				} 
				else {  // ** Else response was unsuccessful
					alert('Error with AJAX');
				}
			}
		);
	});
	
});