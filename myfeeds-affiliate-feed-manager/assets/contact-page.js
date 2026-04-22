(function(){
    var wrap = document.querySelector('.myfeeds-contact-wrap');
    if (!wrap) return;

    var cfg = window.myfeedsContact || {};

    // ── FAQ Accordion ──
    var faqItems = wrap.querySelectorAll('.myfeeds-faq-item');
    faqItems.forEach(function(item) {
        var question = item.querySelector('.myfeeds-faq-question');
        question.addEventListener('click', function() {
            var wasOpen = item.classList.contains('open');
            // Close all
            faqItems.forEach(function(i) { i.classList.remove('open'); });
            // Toggle clicked
            if (!wasOpen) {
                item.classList.add('open');
            }
        });
    });

    // ── Contact Form Submit ──
    var submitBtn = document.getElementById('myfeeds-contact-submit');
    var msgEl     = document.getElementById('myfeeds-contact-msg');
    var formEl    = document.getElementById('myfeeds-contact-form');
    var successEl = document.getElementById('myfeeds-contact-success');

    submitBtn.addEventListener('click', function() {
        var category = document.getElementById('myfeeds-contact-category').value;
        var subject  = document.getElementById('myfeeds-contact-subject').value.trim();
        var message  = document.getElementById('myfeeds-contact-message').value.trim();

        // Validate
        if (!subject || !message) {
            msgEl.textContent = 'Please fill in both subject and message.';
            msgEl.className = 'myfeeds-contact-msg error';
            return;
        }

        // Disable button
        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending...';
        msgEl.textContent = '';
        msgEl.className = 'myfeeds-contact-msg';

        var data = new FormData();
        data.append('action', 'myfeeds_send_contact');
        data.append('nonce', cfg.nonce);
        data.append('category', category);
        data.append('subject', subject);
        data.append('message', message);

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            if (res.success) {
                formEl.style.display = 'none';
                successEl.style.display = 'block';
            } else {
                msgEl.textContent = res.data && res.data.message ? res.data.message : 'Something went wrong.';
                msgEl.className = 'myfeeds-contact-msg error';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Send Message';
            }
        })
        .catch(function() {
            msgEl.textContent = 'Network error. Please try again.';
            msgEl.className = 'myfeeds-contact-msg error';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Send Message';
        });
    });
})();
