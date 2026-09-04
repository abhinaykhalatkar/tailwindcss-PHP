const buttonClasses = {
  primary: 'inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md shadow-sm',
  secondary: 'inline-flex items-center px-4 py-2 bg-white hover:bg-gray-50 text-gray-700 border border-gray-300 rounded-md',
};

function classNames(...classes) {
  return classes.filter(Boolean).join(' ');
}

document.querySelector('.toggle').classList.add('opacity-0', 'pointer-events-none');
