function romanSubQuestions(count, groupNumber, maxEach) {
  const roman = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x', 'xi', 'xii']
  return Array.from({ length: count }, (_, i) => ({
    key: `Q${groupNumber}.${roman[i]}`,
    label: `Q${groupNumber}.${roman[i]}`,
    max: maxEach,
  }))
}

// Dummy question structure for the demo. In a real system this would come
// from the uploaded question paper / answer key, not be hardcoded.
export const questionScheme = [
  {
    title: 'Group A',
    maxAttempt: 10,
    questions: romanSubQuestions(12, 1, 1),
  },
  {
    title: 'Group B',
    maxAttempt: 3,
    questions: [
      { key: 'Q2', label: 'Q2', max: 5 },
      { key: 'Q3', label: 'Q3', max: 5 },
      { key: 'Q4', label: 'Q4', max: 5 },
      { key: 'Q5', label: 'Q5', max: 5 },
      { key: 'Q6', label: 'Q6', max: 5 },
    ],
  },
]

export const maxTotalMarks = questionScheme.reduce(
  (sum, group) => sum + group.questions.reduce((s, q) => s + q.max, 0),
  0,
)
