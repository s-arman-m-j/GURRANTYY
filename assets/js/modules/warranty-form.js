export const warrantyForm = {
    init() {
        const form = document.querySelector('.warranty-form');
        if (form) {
            this.form = form;
            this.bindEvents();
        }
    },

    bindEvents() {
        this.form.addEventListener('submit', this.handleSubmit.bind(this));
    },

    async handleSubmit(e) {
        e.preventDefault();
        try {
            const formData = new FormData(this.form);
            const response = await this.submitForm(formData);
            ASG.notifications.show(response.message, response.success ? 'success' : 'error');
        } catch (error) {
            ASG.notifications.show('خطا در ارسال فرم', 'error');
        }
    },

    async submitForm(formData) {
        const response = await fetch(ASG.config.ajaxUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'X-WP-Nonce': ASG.config.nonce
            }
        });

        if (!response.ok) {
            throw new Error('Network response was not ok');
        }

        return await response.json();
    }
};