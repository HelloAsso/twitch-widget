function updateDonationGoalPreview() {
    var back = document.querySelector('.back');
    var front = document.querySelector('.front');
    var backTitle = document.getElementById('back-title');
    var frontTitle = document.getElementById('front-title');

    back.style.backgroundColor = document.getElementById('background_color').value;
    front.style.backgroundColor = document.getElementById('bar_color').value;

    back.style.color = document.getElementById('text_color_main').value;
    front.style.color = document.getElementById('text_color_alt').value;

    backTitle.textContent = document.getElementById('text_content').value;
    frontTitle.textContent = document.getElementById('text_content').value;
}

document.getElementById('text_color_main').addEventListener('input', updateDonationGoalPreview);
document.getElementById('text_color_alt').addEventListener('input', updateDonationGoalPreview);
document.getElementById('text_content').addEventListener('input', updateDonationGoalPreview);
document.getElementById('bar_color').addEventListener('input', updateDonationGoalPreview);
document.getElementById('background_color').addEventListener('input', updateDonationGoalPreview);

document.getElementById('previewBtn').addEventListener('click', function() {
    displayAlertBox('test de pseudo', 'test de message', '1000');
});
