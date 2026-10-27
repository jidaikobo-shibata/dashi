/** 公開アップロードは受領IDだけで引き継ぐ。ファイル名やEXIFを信用しない。 */
jQuery(function ($) {
    $('.dashi_form input[type="file"]').each(function () {
        var input = $(this);
        var name = input.attr('name');
        var area = $('<div>', { 'class': '__uploader' }).attr('data-name', name);
        var uploadButton = $('<button>', { type: 'button', 'class': 'button' }).text('ファイルをアップロード');
        var clearButton = $('<button>', { type: 'button', 'class': 'button' }).text('クリア');
        var preview = $('<div>', { 'class': '__uploader_uploaded' });
        var status = $('<div>', { role: 'status', 'aria-live': 'polite' });
        area.append(uploadButton, clearButton, preview, status);
        input.after(area);
        input.hide().prop('disabled', true);

        function render(file, url) {
            preview.empty();
            if (!file || !file.upload_id || !url) return;
            var label = file.original_name || file.name;
            if ((file.type || '').indexOf('image/') === 0) {
                preview.append($('<img>').attr({ src: url, alt: label }).css('max-width', '200px'));
            } else {
                preview.append($('<a>').attr({ href: url, target: '_blank', rel: 'noopener noreferrer' }).text(label));
            }
            preview.append($('<input>', { type: 'hidden', 'class': 'filevalue' })
                .attr('name', name + '[upload_id]').val(file.upload_id));
        }

        var session = (DashiUpload.session || {})[DashiUpload.form] || {};
        render(session[name], (DashiUpload.session_urls || {})[name]);
        clearButton.on('click', function () { preview.empty(); status.text('ファイルの選択を解除しました。'); });
        uploadButton.on('click', function () {
            var picker = $('<input>', { type: 'file' });
            picker.on('change', function () {
                var file = this.files[0];
                if (!file) return;
                var limit = parseInt(DashiUpload.max_upload_size || 0, 10);
                if (limit > 0 && file.size > limit) {
                    status.text('ファイルサイズが上限を超えています。');
                    return;
                }
                var data = new FormData();
                data.append('action', DashiUpload.action);
                data.append('_wpnonce', DashiUpload.upload_nonce);
                data.append('file_data', file);
                data.append('form', DashiUpload.form);
                data.append('field', name);
                status.text('アップロード中…');
                uploadButton.prop('disabled', true);
                $.ajax({ url: DashiUpload.ajax_url, type: 'POST', dataType: 'json', data: data,
                    processData: false, contentType: false
                }).done(function (result) {
                    if (!result || !result.success || !result.data || !result.data.upload_id) {
                        status.text('アップロードに失敗しました。');
                        return;
                    }
                    render(result.data, result.data.path);
                    status.text('アップロードしました。');
                }).fail(function (xhr) {
                    var error = xhr.responseJSON && xhr.responseJSON.data;
                    status.text(error && error.message ? error.message : 'アップロードに失敗しました。');
                }).always(function () { uploadButton.prop('disabled', false); });
            });
            picker.trigger('click');
        });
    });
});
