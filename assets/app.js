'use strict';
document.addEventListener('click', (event) => {
    const openButton = event.target.closest('[data-open]');
    if (openButton) {
        const dialog = document.getElementById(openButton.dataset.open);
        if (dialog) dialog.showModal();
    }
    const closeButton = event.target.closest('[data-close]');
    if (closeButton) closeButton.closest('dialog').close();
    const noticeButton = event.target.closest('.notice-close');
    if (noticeButton) noticeButton.closest('.notice').remove();
    const issueButton = event.target.closest('[data-issue-user]');
    if (issueButton) {
        const form = document.getElementById('issue-form');
        form.reset();
        document.getElementById('issue-user').value = issueButton.dataset.issueUser;
        document.getElementById('issue-reader-name').textContent = issueButton.dataset.userName;
        document.getElementById('issue-request').value = issueButton.dataset.requestId || '0';
        const radios = [...form.querySelectorAll('[name="book_id"]')];
        const requestedBook = issueButton.dataset.bookId;
        let found = !requestedBook;
        radios.forEach((radio) => {
            radio.checked = radio.value === requestedBook;
            radio.closest('.issue-choice').hidden = Boolean(requestedBook && radio.value !== requestedBook);
            if (radio.checked) found = true;
        });
        document.getElementById('issue-unavailable').hidden = found;
        document.getElementById('issue-submit').disabled = !found || radios.length === 0;
        document.getElementById('issue-modal').showModal();
    }
    if (event.target instanceof HTMLDialogElement) {
        const rect = event.target.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) event.target.close();
    }
});
document.addEventListener('submit', (event) => {
    const form = event.target;
    // Снижает вероятность двойного нажатия; целостность дополнительно обеспечивает БД.
    form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => {
        button.disabled = true;
    });
});
