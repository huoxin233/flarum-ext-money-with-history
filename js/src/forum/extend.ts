import Extend from 'flarum/common/extenders';
import User from 'flarum/common/models/User';
import UserMoneyHistory from './models/UserMoneyHistory';
import MoneyHistoryPage from './components/MoneyHistoryPage';

export default [
  new Extend.Store().add('userMoneyHistory', UserMoneyHistory),

  new Extend.Model(User).attribute<boolean>('canEditMoney').attribute<boolean>('canQueryOthersMoneyHistory'),

  new Extend.Routes().add('userMoneyHistory', '/u/:username/money/history', MoneyHistoryPage),
];
