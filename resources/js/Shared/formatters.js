const currencyFormatter = new Intl.NumberFormat('en-PH', {
  style: 'currency',
  currency: 'PHP',
  minimumFractionDigits: 2,
});

const numberFormatter = new Intl.NumberFormat('en-PH');

export const formatCurrency = (value) => currencyFormatter.format(Number(value || 0));

export const formatNumber = (value) => numberFormatter.format(Number(value || 0));

export const formatDateTime = (value) => {
  if (!value) {
    return 'Not available';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
};

export const formatDate = (value) => {
  if (!value) {
    return 'Not available';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
  }).format(date);
};
