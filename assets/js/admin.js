jQuery(document).ready(function($) {
    // Handle logo upload
    $(document).on('click', '.wtsg-upload-logo', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var container = button.closest('.wtsg-logo-upload');
        var urlInput = container.find('.wtsg-logo-url');
        var idInput = container.find('.wtsg-logo-id');
        var preview = container.find('.wtsg-logo-preview');
        
        // Create the media frame
        var frame = wp.media({
            title: wtsgAdmin.title,
            button: {
                text: wtsgAdmin.button
            },
            multiple: false,
            library: {
                type: 'image'
            }
        });

        // When an image is selected
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            
            // Update the hidden inputs
            urlInput.val(attachment.url);
            idInput.val(attachment.id);
            
            // Update the preview
            preview.html('<img src="' + attachment.url + '" alt="" style="max-width: 200px; max-height: 100px;" />');
            
            // Update the button text
            button.text(wtsgAdmin.change);
            
            // Show the remove button if it doesn't exist
            if (!container.find('.wtsg-remove-logo').length) {
                button.after('<button type="button" class="button wtsg-remove-logo" style="margin-left: 5px;">' + wtsgAdmin.remove + '</button>');
            }
        });

        // Open the media frame
        frame.open();
    });

    // Handle logo removal
    $(document).on('click', '.wtsg-remove-logo', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var container = button.closest('.wtsg-logo-upload');
        var urlInput = container.find('.wtsg-logo-url');
        var idInput = container.find('.wtsg-logo-id');
        var preview = container.find('.wtsg-logo-preview');
        var uploadButton = container.find('.wtsg-upload-logo');
        
        // Clear the inputs
        urlInput.val('');
        idInput.val('');
        
        // Clear the preview
        preview.empty();
        
        // Update the upload button text
        uploadButton.text(wtsgAdmin.upload);
        
        // Remove the remove button
        button.remove();
    });
}); 