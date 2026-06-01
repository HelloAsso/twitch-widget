import { displayAlertBox } from './alert.js';

/**
 * Lie une liste d'inputs à une fonction de mise à jour de preview.
 * Exécute la fonction immédiatement, puis sur chaque événement 'input'.
 */
function bindPreviewInputs(inputIds, updateFn) {
    updateFn();
    inputIds.forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updateFn);
    });
}

function updateDonationGoalPreview() {
    const back = document.querySelector('.back');
    const front = document.querySelector('.front');
    const backTitle = document.getElementById('back-title');
    const frontTitle = document.getElementById('front-title');
    // L'objectif est maintenant dans le formulaire d'infos (stream_goal ou event_goal)
    const goalInput = document.getElementById('stream_goal') || document.getElementById('event_goal');

    back.style.backgroundColor = document.getElementById('background_color').value;
    front.style.backgroundColor = document.getElementById('bar_color').value;

    back.style.color = document.getElementById('text_color_main').value;
    front.style.color = document.getElementById('text_color_alt').value;

    const textContent = document.getElementById('text_content').value;
    backTitle.textContent = textContent;
    frontTitle.textContent = textContent;

    const goalValue = goalInput ? (parseFloat(goalInput.value) || 0) : 0;
    const currentDonation = goalValue / 2;

    document.getElementById('back-goal-total').textContent = `${goalValue} €`;
    document.getElementById('front-goal-total').textContent = `${goalValue} €`;
    document.getElementById('back-goal-current').textContent = `${currentDonation} €`;
    document.getElementById('front-goal-current').textContent = `${currentDonation} €`;

    front.style.width = goalValue > 0 ? `${(currentDonation / goalValue) * 100}%` : '0%';
}

const donationBarForm = document.getElementById('donationBarForm');
if (donationBarForm) {
    bindPreviewInputs(
        ['text_color_main', 'text_color_alt', 'text_content', 'bar_color', 'background_color', 'stream_goal', 'event_goal'],
        updateDonationGoalPreview
    );
}

const alertBoxForm = document.getElementById('alertBoxForm');
if (alertBoxForm) {
    document.getElementById('previewBtn').addEventListener('click', () => {
        displayAlertBox('test de pseudo', 'test de message', '1000');
    });
}

// ── Card widget live preview ──────────────────────────────────
const cardWidgetForm = document.getElementById('cardWidgetForm');
if (cardWidgetForm) {
    function updateCardPreview() {
        const preview = document.getElementById('cardPreview');
        const tag = document.getElementById('cardPreviewTag');
        const title = document.getElementById('cardPreviewTitle');
        const desc = document.getElementById('cardPreviewDesc');
        const amount = document.getElementById('cardPreviewAmount');
        const barFill = document.getElementById('cardPreviewBarFill');
        const goalEl = document.getElementById('cardPreviewGoal');
        const pct = document.getElementById('cardPreviewPct');

        const bgColor = document.getElementById('card_background_color').value;
        const textColor = document.getElementById('card_text_color').value;
        const barColor = document.getElementById('card_bar_color').value;
        const barBgColor = document.getElementById('card_bar_background_color').value;
        const tagColor = document.getElementById('card_tag_color').value;
        const tagBgColor = document.getElementById('card_tag_background_color').value;
        // L'objectif est maintenant dans le formulaire d'infos (stream_goal ou event_goal)
        const goalInput = document.getElementById('stream_goal') || document.getElementById('event_goal');
        const goalValue = goalInput ? (parseFloat(goalInput.value) || 1) : 1;

        if (preview) {
            preview.style.backgroundColor = bgColor;
            preview.style.color = textColor;
        }
        if (tag) {
            tag.style.color = tagColor;
            tag.style.backgroundColor = tagBgColor;
            tag.textContent = `✏️ ${document.getElementById('card_tag').value || ''}`;
        }
        if (title) title.textContent = document.getElementById('card_title').value || '';
        if (desc) desc.textContent = document.getElementById('card_description').value || '';

        const halfGoal = goalValue / 2;
        if (amount) amount.textContent = `${halfGoal} €`;
        if (barFill) {
            barFill.style.backgroundColor = barColor;
            barFill.style.width = '50%';
            barFill.parentElement.style.backgroundColor = barBgColor;
        }
        if (goalEl) goalEl.innerHTML = `Objectif : <strong>${goalValue} €</strong>`;
        if (pct) pct.textContent = '50%';

        const cta = document.getElementById('cardPreviewCta');
        if (cta) {
            cta.style.color = tagColor;
            cta.style.backgroundColor = tagBgColor;
        }
    }

    bindPreviewInputs(
        [
            'card_tag', 'card_title', 'card_description',
            'card_background_color', 'card_text_color', 'card_bar_color',
            'card_bar_background_color', 'card_tag_color', 'card_tag_background_color',
            'stream_goal', 'event_goal',
        ],
        updateCardPreview
    );

    // Live image preview
    const cardImageInput = document.getElementById('card_image');
    if (cardImageInput) {
        cardImageInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = (e) => {
                const imgEl = document.getElementById('cardPreviewImage');
                if (imgEl) imgEl.style.backgroundImage = `url(${e.target.result})`;
            };
            reader.readAsDataURL(file);
        });
    }
}
