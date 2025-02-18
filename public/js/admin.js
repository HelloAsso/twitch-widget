function updateDonationGoalPreview() {
    var back = document.querySelector('.back');
    var front = document.querySelector('.front');
    var backTitle = document.getElementById('back-title');
    var frontTitle = document.getElementById('front-title');
    var goal = document.getElementById('goal');

    back.style.backgroundColor = document.getElementById('background_color').value;
    front.style.backgroundColor = document.getElementById('bar_color').value;

    back.style.color = document.getElementById('text_color_main').value;
    front.style.color = document.getElementById('text_color_alt').value;

    backTitle.textContent = document.getElementById('text_content').value;
    frontTitle.textContent = document.getElementById('text_content').value;
    goal.textContent = document.getElementById('goal').value;

    var currentDonation = goal.value / 2;

    document.getElementById('back-goal-total').textContent = goal.value + ' €';
    document.getElementById('front-goal-total').textContent = goal.value + ' €';
    document.getElementById('back-goal-current').textContent = currentDonation + ' €';
    document.getElementById('front-goal-current').textContent = currentDonation + ' €';

    front.style.width = (currentDonation / goal * 100) + '%';
}

var donationBarForm = document.getElementById('donationBarForm');
if (donationBarForm) {
    document.getElementById('text_color_main').addEventListener('input', updateDonationGoalPreview);
    document.getElementById('text_color_alt').addEventListener('input', updateDonationGoalPreview);
    document.getElementById('text_content').addEventListener('input', updateDonationGoalPreview);
    document.getElementById('bar_color').addEventListener('input', updateDonationGoalPreview);
    document.getElementById('background_color').addEventListener('input', updateDonationGoalPreview);
    document.getElementById('goal').addEventListener('input', updateDonationGoalPreview);
}

var alertBoxForm = document.getElementById('alertBoxForm');
if (alertBoxForm) {
    document.getElementById('previewBtn').addEventListener('click', function () {
        displayAlertBox('test de pseudo', 'test de message', '1000');
    });
}
