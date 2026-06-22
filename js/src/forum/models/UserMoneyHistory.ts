import Model from 'flarum/common/Model';
import User from 'flarum/common/models/User';

export default class UserMoneyHistory extends Model {
  balanceDelta = Model.attribute<number>('balanceDelta');
  source = Model.attribute<string>('source');
  sourceKey = Model.attribute<string | null>('sourceKey');
  sourceParams = Model.attribute<Record<string, unknown> | null>('sourceParams');
  createdAt = Model.attribute<Date, string>('createdAt', Model.transformDate);
  balanceBefore = Model.attribute<number>('balanceBefore');
  balanceAfter = Model.attribute<number>('balanceAfter');
  user = Model.hasOne<User>('user');
  actor = Model.hasOne<User>('actor');
}
