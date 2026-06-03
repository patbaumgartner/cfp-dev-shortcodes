function change(id) {
  const ratings = ['Poor', 'Fair', 'Good', 'Very Good', 'Excellent'];
  const cname = document.getElementById(id).className;
  const rating = document.getElementById(id + "-hidden").value;
  document.getElementById(cname + "-rating").innerHTML = rating;
  document.getElementById(cname + "-rating").value = rating;
  for (let i = rating; i >= 1; i--) {
    document.getElementById(cname + i).src = "https://s3.eu-west-1.amazonaws.com/cfp.dev/images/star2.png";
  }
  const id2 = parseInt(rating) + 1;
  for (let j = id2; j <= 5; j++) {
    document.getElementById(cname + j).src = "https://s3.eu-west-1.amazonaws.com/cfp.dev/images/star1.png";
  }
  document.getElementById("dev-cfp-rating-txt").innerHTML = ratings[rating-1];
}
